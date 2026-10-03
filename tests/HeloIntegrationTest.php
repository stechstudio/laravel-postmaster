<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\EmailDelivered;
use STS\Postmaster\EmailDropped;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\EmailUnsubscribed;
use STS\Postmaster\EmailResubscribed;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Jobs\ProcessWebhook;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;

class HeloIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.persistence.store_content', true);
        $app['config']->set('postmaster.providers.helo.signing_key', 'signing-key');
        $app['config']->set('postmaster.providers.helo.api_key', 'api-key');
        $app['config']->set('postmaster.providers.helo.channel_id', 'channel-id');
        $app['config']->set('mail.default', 'helo');
        $app['config']->set('mail.mailers.helo', ['transport' => 'helo']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 9, 20)->setTime(12, 0));
    }

    protected function webhook(string $type = 'email-delivered', array $extra = [], bool $signed = true)
    {
        $payload = HeloAdapterTest::payload($type, array_replace(['timestamp' => now()->toISOString()], $extra));
        $body = json_encode($payload);
        $time = now()->timestamp;
        $signature = hash_hmac('sha256', $time.'.'.$body, 'signing-key');
        return $this->call('POST', '/webhooks/postmaster/helo', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HELO_WEBHOOK_SIGNATURE' => $signed ? "t=$time,v1=$signature" : 'invalid',
        ], content: $body);
    }

    public function testCorrelatesApiSendsAndSignedWebhooksPerRecipient(): void
    {
        $id = HeloAdapterTest::payload()['messageId'];
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => $id])]);
        $sent = Mail::raw('Test body', fn ($mail) => $mail->from('sender@example.com')->to('recipient@example.com')->cc('copy@example.com')->bcc('blind@example.com')->subject('Helo test'));
        $this->assertSame($id, $sent->getMessageId());
        $this->assertSame(3, EmailMessage::count());
        $this->assertSame(3, EmailMessage::where('provider_message_id', $id)->count());
        Event::fake([EmailDelivered::class]);
        $this->travel(1)->seconds();
        $this->webhook()->assertOk();
        $this->webhook(extra: ['recipient' => 'copy@example.com'])->assertOk();
        $this->assertSame(3, EmailMessage::count());
        $this->assertSame(2, EmailMessage::delivered()->count());
        $this->assertSame('sent', EmailMessage::where('to_address', 'blind@example.com')->sole()->status);
        Event::assertDispatched(EmailDelivered::class, fn ($event) => $event->emailMessage()?->subject === 'Helo test');
        $this->webhook()->assertOk();
        $this->assertSame(1, EmailActivity::where('status', 'delivered')->whereHas('emailMessage', fn ($q) => $q->where('to_address', 'recipient@example.com'))->count());
    }

    public function testRecordsPartialSuppressionsAndDelayedSubmission(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'delayed', 'messageId' => 'helo-delayed', 'suppressions' => ['copy@example.com']])]);
        Event::fake([EmailDropped::class]);
        Mail::raw('Test body', fn ($mail) => $mail->from('sender@example.com')->to('recipient@example.com')->cc('copy@example.com'));
        $this->assertSame('deferred', EmailMessage::where('to_address', 'recipient@example.com')->sole()->status);
        $this->assertSame('dropped', EmailMessage::where('to_address', 'copy@example.com')->sole()->status);
        $this->assertTrue(EmailAddress::where('address', 'copy@example.com')->sole()->isSuppressed());
        Event::assertDispatched(EmailDropped::class, fn ($event) => $event->toAddress() === 'copy@example.com' && $event->emailMessage() !== null);
    }

    public function testRejectsBadSignaturesBeforeQueueingOrWriting(): void
    {
        Bus::fake();
        config(['postmaster.queue_webhooks' => true]);
        $this->webhook(signed: false)->assertStatus(403);
        Bus::assertNothingDispatched();
        $this->assertSame(0, EmailMessage::count());
    }

    public function testAcknowledgesQueuedWebhooksWith200AndProcessesThePayload(): void
    {
        Bus::fake();
        config(['postmaster.queue_webhooks' => true]);
        $this->webhook()->assertOk();
        $this->assertSame(0, EmailMessage::count());
        Bus::assertDispatched(ProcessWebhook::class, function ($job) {
            $this->assertSame('helo', $job->provider);
            $job->handle(app(\STS\Postmaster\Postmaster::class));
            return true;
        });
        $this->assertSame('delivered', EmailMessage::sole()->status);
    }

    public function testUnsubscribeSurvivesDeliveryAndOnlyResubscribeClearsIt(): void
    {
        Event::fake([EmailUnsubscribed::class, EmailResubscribed::class]);
        $this->webhook('recipient-unsubscribed')->assertOk();
        $address = EmailAddress::sole();
        $this->assertSame('unsubscribed', $address->reason);
        $this->travel(1)->seconds();
        $this->webhook()->assertOk();
        $this->assertTrue($address->fresh()->isSuppressed());
        $this->travel(1)->seconds();
        $this->webhook('recipient-resubscribed')->assertOk();
        $this->assertFalse($address->fresh()->isSuppressed());
        Event::assertDispatched(EmailUnsubscribed::class);
        Event::assertDispatched(EmailResubscribed::class);
        // An old retry must not undo the newer opt-in.
        $this->webhook('recipient-unsubscribed', ['timestamp' => now()->subMinutes(1)->toISOString()])->assertOk();
        $this->assertFalse($address->fresh()->isSuppressed());
    }

    public function testResubscribeDoesNotClearManualOrComplaintSuppressions(): void
    {
        foreach (['manual', 'complained'] as $reason) {
            $address = EmailAddress::firstOrCreate(['address' => 'recipient@example.com']);
            $address->suppress($reason);
            $this->travel(1)->seconds();
            $this->webhook('recipient-resubscribed')->assertOk();
            $this->assertTrue($address->fresh()->isSuppressed());
            $this->assertSame($reason, $address->fresh()->reason);
        }
    }

    public function testAComplaintAfterAnUnsubscribeCannotBeClearedByResubscribing(): void
    {
        $this->webhook('recipient-unsubscribed')->assertOk();
        $this->travel(1)->seconds();
        $this->webhook('recipient-complained')->assertOk();
        $this->travel(1)->seconds();
        $this->webhook('recipient-resubscribed')->assertOk();
        $this->assertSame('complained', EmailAddress::sole()->reason);
        $this->assertTrue(EmailAddress::sole()->isSuppressed());
    }

    public function testResendsStoredAttachmentsThroughHelo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        config(['postmaster.persistence.attachments.store' => true]);
        Http::fake(['api.helohq.com/*' => Http::sequence()
            ->push(['status' => 'accepted', 'messageId' => 'original-id'])
            ->push(['status' => 'accepted', 'messageId' => 'resent-id'])]);
        Mail::to('recipient@example.com')->send(new \STS\Postmaster\Tests\Stubs\FullMail);
        $original = EmailMessage::sole();
        $this->assertSame(1, $original->attachments()->count());
        Postmaster::resend($original);
        $this->assertSame(2, EmailMessage::count());
        $this->assertSame($original->id, EmailMessage::where('provider_message_id', 'resent-id')->sole()->resent_from_id);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(base64_encode('PDF DATA'), $request['attachments'][0]['content']);
        }
    }

    public function testReleasesSandboxMailAndRetainsTracking(): void
    {
        // The boot-time listener is normally enabled by configuration.
        Event::listen(\Illuminate\Mail\Events\MessageSending::class, \STS\Postmaster\Listeners\InterceptSandboxMail::class);
        config(['postmaster.delivery' => 'sandbox']);
        Mail::to('recipient@example.com')->send(new \STS\Postmaster\Tests\Stubs\TrackedMail(tenant: 42));
        Http::assertNothingSent();
        $original = EmailMessage::sole();
        $this->assertTrue($original->isSandboxed());
        $this->assertSame(42, $original->tenant_id);
        Http::fake(['api.helohq.com/*' => Http::response(['status' => 'accepted', 'messageId' => 'released-id', 'suppressions' => ['recipient@example.com']])]);
        Postmaster::release($original);
        $this->assertSame(1, EmailMessage::count());
        $this->assertSame('released-id', $original->fresh()->provider_message_id);
        $this->assertSame('dropped', $original->fresh()->status);
        $this->assertSame(42, $original->fresh()->tenant_id);
    }

    public function testOldResubscribeDoesNotClearANewerUnsubscribe(): void
    {
        $this->webhook('recipient-unsubscribed')->assertOk();
        $this->webhook('recipient-resubscribed', ['timestamp' => now()->subMinutes(1)->toISOString()])->assertOk();
        $this->assertTrue(EmailAddress::sole()->isSuppressed());
    }

    public function testSubscriptionOrderingPreservesSubsecondPrecision(): void
    {
        $this->webhook('recipient-unsubscribed', ['timestamp' => '2026-09-20T12:00:00.900000Z'])->assertOk();
        $this->webhook('recipient-resubscribed', ['timestamp' => '2026-09-20T12:00:00.100000Z'])->assertOk();
        $address = EmailAddress::sole();
        $this->assertTrue($address->isSuppressed());
        $this->assertSame('900000', $address->suppressed_at->format('u'));
        $this->assertSame('900000', $address->last_event_at->format('u'));
        $this->webhook('recipient-resubscribed', ['timestamp' => '2026-09-20T12:00:00.950000Z'])->assertOk();
        $this->assertFalse($address->fresh()->isSuppressed());
    }

    public function testBouncesSuppressOnlyPermanentFailures(): void
    {
        $this->webhook('email-bounced', ['details' => ['type' => 'Transient']])->assertOk();
        $this->assertFalse(EmailAddress::sole()->isSuppressed());
        $this->travel(1)->seconds();
        $this->webhook('email-bounced', ['details' => ['type' => 'Permanent']])->assertOk();
        $this->assertTrue(EmailAddress::sole()->isSuppressed());
    }

    public function testSyncDryRunAndFailedFetchDoNotChangeLocalState(): void
    {
        Http::fake(['api.helohq.com/*' => Http::response(['totalCount' => 1, 'results' => [['email' => 'bounce@example.com', 'reason' => 'bounce', 'createdAt' => now()->toISOString()]]])]);
        $this->artisan('postmaster:sync', ['--provider' => 'helo', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, EmailAddress::count());
        $this->artisan('postmaster:sync', ['--provider' => 'helo'])->assertSuccessful();
        $this->assertTrue(EmailAddress::sole()->isSuppressed());
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api.helohq.com/*' => Http::response(['totalCount' => 1, 'results' => []])]);
        $this->artisan('postmaster:sync', ['--provider' => 'helo'])->expectsOutputToContain('incomplete')->assertFailed();
        $this->assertTrue(EmailAddress::sole()->isSuppressed());
    }

    public function testHeloSyncCannotClearAnotherProvidersSuppression(): void
    {
        foreach ([['SendGrid'], ['Helo', 'SendGrid']] as $index => $providers) {
            EmailAddress::create(['address' => "recipient{$index}@example.com", 'providers' => $providers,
                'status' => 'suppressed', 'reason' => 'bounced', 'suppressed_at' => now()]);
        }
        Http::fake(['api.helohq.com/*' => Http::response(['totalCount' => 0, 'results' => []])]);
        $this->artisan('postmaster:sync', ['--provider' => 'helo'])->assertSuccessful();
        $this->assertSame(2, EmailAddress::where('status', 'suppressed')->count());
    }

    public function testSyncUpdatesAnExistingDropToAnOptOut(): void
    {
        EmailAddress::create(['address' => 'recipient@example.com', 'providers' => ['Helo'],
            'status' => 'suppressed', 'reason' => 'dropped', 'suppressed_at' => now()]);
        Http::fake(['api.helohq.com/*' => Http::response(['totalCount' => 1, 'results' => [
            ['email' => 'recipient@example.com', 'reason' => 'unsubscribe', 'createdAt' => now()->toISOString()],
        ]])]);
        $this->artisan('postmaster:sync', ['--provider' => 'helo'])->assertSuccessful();
        $this->assertSame('unsubscribed', EmailAddress::sole()->reason);
        $this->webhook()->assertOk();
        $this->assertTrue(EmailAddress::sole()->isSuppressed());
    }

    public function testRejectedRemoteRemovalIsReportedForManualFollowUp(): void
    {
        $this->webhook('recipient-complained')->assertOk();
        Http::fake(['api.helohq.com/*' => Http::response(['results' => [['email' => 'recipient@example.com', 'success' => false, 'message' => 'Cannot remove a complaint']]])]);
        $result = Postmaster::unsuppress('recipient@example.com');
        $this->assertSame([], $result['cleared']);
        $this->assertSame(['Helo'], $result['manual']);
    }

    public function testSetupReportDetectsHeloAndDoesNotPrintSecrets(): void
    {
        $this->artisan('postmaster:install', ['--no-interaction' => true])
            ->expectsOutputToContain('webhooks/postmaster/helo')
            ->expectsOutputToContain('POSTMASTER_HELO_SIGNING_KEY is set')
            ->doesntExpectOutputToContain('signing-key')
            ->assertSuccessful();
    }
}
