<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;

/**
 * Sends through a stub that behaves like the postshiba/postshiba transport:
 * it sets the Message-ID PostShiba returns, which comes back as smtp-id.
 */
class PostShibaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.providers.postshiba.signing_secret', 'signing-secret');
        $app['config']->set('postmaster.providers.postshiba.api_key', 'api-key');
        $app['config']->set('postmaster.providers.postshiba.team_id', 'OjMnKv');
        $app['config']->set('mail.default', 'postshiba');
        $app['config']->set('mail.mailers.postshiba', ['transport' => 'postshiba']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        app('mail.manager')->extend('postshiba', fn () => new class extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                $original = $message->getOriginalMessage();
                if ($original instanceof Message) {
                    $original->getHeaders()->addIdHeader('Message-ID', 'abc@capsule.test');
                }
                $message->setMessageId('abc@capsule.test');
            }

            public function __toString(): string
            {
                return 'postshiba';
            }
        });
    }

    /** PostShiba always posts a JSON array, even for one event. */
    protected function webhook(array $events, ?string $secret = 'signing-secret', array $headers = [])
    {
        $body = json_encode($events);
        $timestamp = time();

        return $this->call('POST', '/webhooks/postmaster/postshiba', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CAPSULE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CAPSULE_SIGNATURE' => PostShibaSignatureAuthTest::sign($body, (string) $secret, $timestamp),
        ] + $headers, content: $body);
    }

    protected function event(string $fixture, array $overrides = []): array
    {
        return array_replace(PostShibaAdapterTest::fixture($fixture), $overrides);
    }

    protected function send(): void
    {
        Mail::raw('Test body', fn ($mail) => $mail->from('sender@example.com')->to('recipient@example.com')->cc('copy@example.com')->subject('PostShiba test'));
    }

    public function testCorrelatesSignedWebhooksWithTheSentMessage(): void
    {
        $this->send();
        $this->assertSame(2, EmailMessage::where('provider_message_id', 'abc@capsule.test')->count());

        $this->webhook([
            $this->event('delivered'),
            $this->event('bounce', ['email' => 'copy@example.com']),
        ])->assertOk();

        $this->assertSame('delivered', EmailMessage::where('to_address', 'recipient@example.com')->sole()->status);
        $this->assertSame('bounced', EmailMessage::where('to_address', 'copy@example.com')->sole()->status);
        $this->assertTrue(EmailAddress::where('address', 'copy@example.com')->sole()->isSuppressed());
    }

    public function testRecordsARetriedEventOnce(): void
    {
        $this->send();
        $message = EmailMessage::where('to_address', 'recipient@example.com')->sole();

        $this->webhook([$this->event('deferred')])->assertOk();
        $this->webhook([$this->event('deferred')])->assertOk();

        $this->assertSame(1, $message->activity()->where('status', 'deferred')->count());
    }

    public function testRejectsUnsignedWebhooks(): void
    {
        $this->send();
        $this->webhook([$this->event('delivered')], 'wrong')->assertForbidden();
        $this->assertSame('sent', EmailMessage::where('to_address', 'recipient@example.com')->sole()->status);
    }

    public function testAcceptsTestEventsWithoutRecordingThem(): void
    {
        Log::spy();
        $this->send();

        $this->webhook([$this->event('test')], headers: ['HTTP_X_CAPSULE_TEST' => '1'])->assertSuccessful();

        $this->assertSame('sent', EmailMessage::where('to_address', 'recipient@example.com')->sole()->status);
        Log::shouldNotHaveReceived('warning');
    }

    public function testSyncsAndUnsuppressesThroughTheApi(): void
    {
        Http::fake(fn (HttpRequest $request) => str_ends_with($request->url(), '/bulk_delete')
            ? Http::response(['deleted' => 1, 'more' => false])
            : Http::response(json_decode(file_get_contents(__DIR__.'/fixtures/postshiba/suppressions.json'), true)));

        $this->artisan('postmaster:sync', ['--provider' => 'postshiba'])->assertSuccessful();
        $this->assertTrue(EmailAddress::where('address', 'bounce@example.com')->sole()->isSuppressed());

        Postmaster::unsuppress('bounce@example.com');

        $this->assertFalse(EmailAddress::where('address', 'bounce@example.com')->sole()->isSuppressed());
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://app.postshiba.com/api/v1/teams/OjMnKv/suppressions/bulk_delete'
            && $request['emails'] === ['bounce@example.com']);
    }
}
