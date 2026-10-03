<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\EmailDropped;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Providers\MailerSend\SignatureAuth;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;

/**
 * Sends through a stub that behaves like mailersend/laravel-driver: it puts
 * the API's x-message-id and any response body on the original message.
 */
class MailerSendIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected const ID = '6892766ae78995a317577aa1';

    public static ?string $responseBody = null;

    public static ?string $messageId = self::ID;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.providers.mailersend.signing_secret', 'signing-secret');
        $app['config']->set('postmaster.providers.mailersend.api_key', 'api-key');
        $app['config']->set('mail.default', 'mailersend');
        $app['config']->set('mail.mailers.mailersend', ['transport' => 'mailersend']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        self::$responseBody = null;
        self::$messageId = self::ID;

        app('mail.manager')->extend('mailersend', fn () => new class extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                $original = $message->getOriginalMessage();
                if ($original instanceof Message && MailerSendIntegrationTest::$messageId) {
                    $original->getHeaders()->addTextHeader('X-MailerSend-Message-Id', MailerSendIntegrationTest::$messageId);
                }
                if ($original instanceof Message && MailerSendIntegrationTest::$responseBody) {
                    $original->getHeaders()->addTextHeader('X-MailerSend-Body', MailerSendIntegrationTest::$responseBody);
                }
            }

            public function __toString(): string
            {
                return 'mailersend';
            }
        });
    }

    protected function webhook(array $payload, ?string $secret = 'signing-secret')
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/postmaster/mailersend', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SIGNATURE' => hash_hmac('sha256', $body, (string) $secret),
        ], content: $body);
    }

    protected function send(): void
    {
        Mail::raw('Test body', fn ($mail) => $mail->from('sender@example.com')->to('recipient@example.com')->cc('copy@example.com')->subject('MailerSend test'));
    }

    public function testCorrelatesSignedWebhooksWithTheSentMessage(): void
    {
        $this->send();
        $this->assertSame(2, EmailMessage::where('provider_message_id', self::ID)->count());

        $this->webhook(MailerSendAdapterTest::payload())->assertOk();
        $this->webhook(MailerSendAdapterTest::payload('activity.hard_bounced', ['recipient' => 'copy@example.com', 'meta' => ['bounce_type' => 'hard', 'bounce_code' => 550]]))->assertOk();

        $this->assertSame('delivered', EmailMessage::where('to_address', 'recipient@example.com')->sole()->status);
        $this->assertSame('bounced', EmailMessage::where('to_address', 'copy@example.com')->sole()->status);
        $this->assertTrue(EmailAddress::where('address', 'copy@example.com')->sole()->isSuppressed());
    }

    public function testRejectsUnsignedWebhooks(): void
    {
        $this->send();
        $this->webhook(MailerSendAdapterTest::payload(), 'wrong')->assertForbidden();
        $this->assertSame('sent', EmailMessage::where('to_address', 'recipient@example.com')->sole()->status);
    }

    public function testAnswersThePingSentWhileCreatingTheWebhook(): void
    {
        Log::spy();
        $this->webhook(['type' => 'webhook.test', 'message' => 'This is a ping test message', 'created_at' => '2026-03-27T07:24:20.577080Z'], SignatureAuth::TEST_SECRET)
            ->assertSuccessful();
        Log::shouldNotHaveReceived('warning');
    }

    public function testRecordsRecipientsMailerSendSuppressedAtSend(): void
    {
        self::$responseBody = json_encode(['message' => 'There are some warnings for your request.', 'warnings' => [[
            'type' => 'SOME_SUPPRESSED', 'warning' => 'Some of the recipients have been suppressed.',
            'recipients' => [['email' => 'Copy@Example.com', 'name' => 'Copy', 'reasons' => ['blocklisted']]],
        ]]]);
        Event::fake([EmailDropped::class]);

        $this->send();

        $this->assertSame('dropped', EmailMessage::where('to_address', 'copy@example.com')->sole()->status);
        $this->assertSame('sent', EmailMessage::where('to_address', 'recipient@example.com')->sole()->status);
        Event::assertDispatched(EmailDropped::class, fn ($event) => $event->reason() === 'blocklisted');
    }

    public function testRecordsAMessageMailerSendDroppedEntirely(): void
    {
        // MailerSend returns no message id when every recipient is suppressed.
        self::$messageId = null;
        self::$responseBody = json_encode(['message' => 'There are some warnings for your request.', 'warnings' => [[
            'type' => 'ALL_SUPPRESSED', 'message' => 'All of the recipients provided have been suppressed.',
            'recipients' => [['email' => 'recipient@example.com', 'reasons' => ['hard_bounced']], ['email' => 'copy@example.com', 'reasons' => ['unsubscribed']]],
        ]]]);

        $this->send();

        $this->assertSame(['dropped'], EmailMessage::pluck('status')->unique()->all());
    }

    public function testSyncsAndUnsuppressesThroughTheApi(): void
    {
        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'DELETE') {
                return Http::response('', 200);
            }

            return Http::response(['data' => str_contains($request->url(), 'hard-bounces')
                ? [['id' => 'b1', 'created_at' => now()->toISOString(), 'recipient' => ['email' => 'bounce@example.com']]]
                : [], 'links' => ['next' => null]]);
        });

        $this->artisan('postmaster:sync', ['--provider' => 'mailersend'])->assertSuccessful();
        $this->assertTrue(EmailAddress::where('address', 'bounce@example.com')->sole()->isSuppressed());

        Postmaster::unsuppress('bounce@example.com');

        $this->assertFalse(EmailAddress::where('address', 'bounce@example.com')->sole()->isSuppressed());
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'DELETE' && $request['ids'] === ['b1']);
    }
}
