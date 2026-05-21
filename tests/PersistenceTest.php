<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Listeners\RelayVerificationEvent;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailMessageEvent;
use STS\Postmaster\Providers\Postmark\Adapter as Postmark;
use STS\Postmaster\Providers\SendGrid\Adapter as SendGrid;
use STS\Postmaster\Tests\Stubs\FullMail;
use STS\Postmaster\Tests\Stubs\Order;
use STS\Postmaster\Tests\Stubs\OrderConfirmationMail;
use STS\Postmaster\Tests\Stubs\RelatedNotification;
use STS\Postmaster\Tests\Stubs\ScopedEmailMessage;
use STS\Postmaster\Tests\Stubs\Tenant;
use STS\Postmaster\Tests\Stubs\TrackedMail;
use STS\Postmaster\Tests\Stubs\TrackedMailMessage;
use Symfony\Component\Mime\Email;

class PersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('mail.default', 'array');
    }

    public function testOutboundMailIsRecorded()
    {
        Mail::raw('Hello there', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertDatabaseCount('email_messages', 1);

        $record = EmailMessage::first();
        $this->assertSame('recipient@example.com', $record->recipient);
        $this->assertSame('Greetings', $record->subject);
        $this->assertSame(EmailEvent::EVENT_SENT, $record->status);
        $this->assertNotEmpty($record->message_id);
        $this->assertNotNull($record->sent_at);
    }

    public function testWebhookEventUpdatesTheExistingRecord()
    {
        $record = EmailMessage::create([
            'message_id' => 'postmark-message-1',
            'recipient'  => 'recipient@example.com',
            'status'     => EmailEvent::EVENT_SENT,
        ]);

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'postmark-message-1',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        $this->assertDatabaseCount('email_messages', 1);

        $record->refresh();
        $this->assertSame(EmailEvent::EVENT_DELIVERED, $record->status);
        $this->assertSame('Postmark', $record->provider);
        $this->assertNotNull($record->last_event_at);
    }

    public function testBounceEventRecordsTheBounceType()
    {
        EmailMessage::create([
            'message_id' => 'postmark-message-2',
            'recipient'  => 'recipient@example.com',
            'status'     => EmailEvent::EVENT_SENT,
        ]);

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'postmark-message-2',
            'Email'      => 'recipient@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $record = EmailMessage::where('message_id', 'postmark-message-2')->first();
        $this->assertSame(EmailEvent::EVENT_BOUNCED, $record->status);
        $this->assertSame(EmailEvent::BOUNCE_HARD, $record->bounce_type);
    }

    public function testRelatedModelIsRecordedFromMailable()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        Mail::to('recipient@example.com')->send(new OrderConfirmationMail($order));

        $record = EmailMessage::first();
        $this->assertSame($order->getMorphClass(), $record->related_type);
        $this->assertSame((string) $order->getKey(), (string) $record->related_id);
    }

    public function testUnrelatedMailLeavesRelatedColumnsNull()
    {
        Mail::raw('Hello there', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $record = EmailMessage::first();
        $this->assertNull($record->related_type);
        $this->assertNull($record->related_id);
    }

    public function testEachRelatedEmailGetsItsOwnAssociation()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $first = Order::create();
        $second = Order::create();

        Mail::to('one@example.com')->send(new OrderConfirmationMail($first));
        Mail::to('two@example.com')->send(new OrderConfirmationMail($second));

        $this->assertCount(1, $first->emailMessages);
        $this->assertCount(1, $second->emailMessages);
        $this->assertSame((string) $first->getKey(), (string) $first->emailMessages->first()->related_id);
        $this->assertSame((string) $second->getKey(), (string) $second->emailMessages->first()->related_id);
    }

    public function testRelatedHeadersAreStrippedBeforeSending()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        Mail::to('recipient@example.com')->send(new OrderConfirmationMail($order));

        $messages = Mail::getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $headers = $messages->first()->getOriginalMessage()->getHeaders();
        $this->assertFalse($headers->has('X-Postmaster-Related-Type'));
        $this->assertFalse($headers->has('X-Postmaster-Related-Id'));
    }

    public function testRelatedModelCanLoadItsEmailMessages()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        Mail::to('recipient@example.com')->send(new OrderConfirmationMail($order));

        $this->assertCount(1, $order->emailMessages);
        $this->assertSame(EmailEvent::EVENT_SENT, $order->emailMessages->first()->status);
        $this->assertTrue($order->is($order->emailMessages->first()->related));
    }

    public function testTenantIsRecordedFromMailableForTenant()
    {
        Mail::to('recipient@example.com')->send(new TrackedMail(tenant: 42));

        $this->assertSame('42', (string) EmailMessage::first()->tenant_id);
    }

    public function testTenantForTenantAcceptsAModel()
    {
        Schema::create('tenants', fn ($table) => $table->id());
        $tenant = Tenant::create();

        Mail::to('recipient@example.com')->send(new TrackedMail(tenant: $tenant));

        $this->assertSame((string) $tenant->getKey(), (string) EmailMessage::first()->tenant_id);
    }

    public function testTenantIsRecordedFromGlobalResolver()
    {
        Postmaster::resolveTenantUsing(fn () => 99);

        Mail::raw('Hello', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertSame('99', (string) EmailMessage::first()->tenant_id);
    }

    public function testExplicitForTenantOverridesTheResolver()
    {
        Postmaster::resolveTenantUsing(fn () => 1);

        Mail::to('recipient@example.com')->send(new TrackedMail(tenant: 2));

        $this->assertSame('2', (string) EmailMessage::first()->tenant_id);
    }

    public function testNotificationCanAssociateViaTheFactoryHelpers()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        NotificationFacade::route('mail', 'recipient@example.com')
            ->notify(new RelatedNotification($order));

        $record = EmailMessage::first();
        $this->assertNotNull($record);
        $this->assertSame($order->getMorphClass(), $record->related_type);
        $this->assertSame((string) $order->getKey(), (string) $record->related_id);
        $this->assertSame('7', (string) $record->tenant_id);
    }

    public function testTracksEmailEventsTraitWorksOnAMailMessageSubclass()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        $message = (new TrackedMailMessage)->relatedTo($order);

        $email = new Email;
        foreach ($message->callbacks as $callback) {
            $callback($email);
        }

        $this->assertSame(
            $order->getMorphClass(),
            $email->getHeaders()->get('X-Postmaster-Related-Type')->getBodyAsString()
        );
    }

    public function testTenantHeaderIsStrippedBeforeSending()
    {
        Mail::to('recipient@example.com')->send(new TrackedMail(tenant: 42));

        $headers = Mail::getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHeaders();
        $this->assertFalse($headers->has('X-Postmaster-Tenant'));
    }

    public function testForTenantScopeFiltersByTenant()
    {
        EmailMessage::create(['message_id' => 'a', 'tenant_id' => 1]);
        EmailMessage::create(['message_id' => 'b', 'tenant_id' => 2]);
        EmailMessage::create(['message_id' => 'c', 'tenant_id' => 1]);

        $this->assertCount(2, EmailMessage::forTenant(1)->get());
        $this->assertCount(1, EmailMessage::forTenant(2)->get());
    }

    public function testTenantRelationResolves()
    {
        Schema::create('tenants', fn ($table) => $table->id());
        config(['postmaster.persistence.tenant_model' => Tenant::class]);
        $tenant = Tenant::create();

        $record = EmailMessage::create(['message_id' => 'a', 'tenant_id' => $tenant->getKey()]);

        $this->assertTrue($tenant->is($record->tenant));
    }

    public function testFullMessageContentIsStoredWhenEnabled()
    {
        config(['postmaster.persistence.store_content' => true]);

        Mail::to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->send(new FullMail);

        $record = EmailMessage::first();

        $this->assertSame('sender@example.com', $record->from_address);
        $this->assertSame('<p>Body</p>', $record->html_body);
        $this->assertSame('Plain body', $record->text_body);
        $this->assertSame(['to@example.com'], array_column($record->recipients['to'], 'address'));
        $this->assertSame(['cc@example.com'], array_column($record->recipients['cc'], 'address'));
        $this->assertSame(['bcc@example.com'], array_column($record->recipients['bcc'], 'address'));
        $this->assertSame(['invoice.pdf'], $record->attachments);
    }

    public function testMessageContentIsNotStoredByDefault()
    {
        Mail::to('to@example.com')->send(new FullMail);

        $record = EmailMessage::first();

        $this->assertNull($record->html_body);
        $this->assertNull($record->text_body);
        $this->assertNull($record->from_address);
        $this->assertNull($record->recipients);
        $this->assertNull($record->attachments);
    }

    public function testPruneContentCommandPurgesOldContentButKeepsTheRecord()
    {
        config(['postmaster.persistence.prune_content_after_days' => 30]);

        $old = EmailMessage::create([
            'message_id'   => 'old',
            'status'       => EmailEvent::EVENT_SENT,
            'html_body'    => '<p>old</p>',
            'from_address' => 'sender@example.com',
        ]);
        $old->created_at = now()->subDays(60);
        $old->save();

        $recent = EmailMessage::create([
            'message_id' => 'recent',
            'html_body'  => '<p>recent</p>',
        ]);

        $this->artisan('postmaster:prune-content')->assertSuccessful();

        $old->refresh();
        $this->assertNull($old->html_body);
        $this->assertNull($old->from_address);
        $this->assertSame(EmailEvent::EVENT_SENT, $old->status);

        $this->assertSame('<p>recent</p>', $recent->refresh()->html_body);
    }

    public function testStatusScopesFilterRecords()
    {
        EmailMessage::create(['message_id' => 'a', 'status' => EmailEvent::EVENT_DELIVERED]);
        EmailMessage::create(['message_id' => 'b', 'status' => EmailEvent::EVENT_BOUNCED]);
        EmailMessage::create(['message_id' => 'c', 'status' => EmailEvent::EVENT_DELIVERED]);

        $this->assertCount(2, EmailMessage::delivered()->get());
        $this->assertCount(1, EmailMessage::bounced()->get());
        $this->assertCount(1, EmailMessage::withStatus(EmailEvent::EVENT_BOUNCED)->get());
    }

    public function testWebhookCorrelationIgnoresGlobalScopes()
    {
        config(['postmaster.persistence.model' => ScopedEmailMessage::class]);

        EmailMessage::create([
            'message_id' => 'scoped-message-1',
            'recipient'  => 'recipient@example.com',
            'status'     => EmailEvent::EVENT_SENT,
        ]);

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'scoped-message-1',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        $this->assertDatabaseCount('email_messages', 1);
        $this->assertSame(
            EmailEvent::EVENT_DELIVERED,
            EmailMessage::where('message_id', 'scoped-message-1')->first()->status
        );
    }

    public function testWebhookEventCreatesARecordWhenNoneExists()
    {
        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'never-seen-before',
            'Email'      => 'recipient@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $this->assertDatabaseCount('email_messages', 1);

        $record = EmailMessage::where('message_id', 'never-seen-before')->first();
        $this->assertNotNull($record);
        $this->assertSame('recipient@example.com', $record->recipient);
        $this->assertSame(EmailEvent::EVENT_BOUNCED, $record->status);
        $this->assertSame(EmailEvent::BOUNCE_HARD, $record->bounce_type);
    }

    public function testTimelineIsNotRecordedByDefault()
    {
        Mail::raw('Hello', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertDatabaseCount('email_messages', 1);
        $this->assertDatabaseCount('email_message_events', 0);
    }

    public function testTheSendSeedsTheTimeline()
    {
        config(['postmaster.persistence.record_events' => true]);

        Mail::raw('Hello', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $record = EmailMessage::first();
        $this->assertCount(1, $record->events);
        $this->assertSame(EmailEvent::EVENT_SENT, $record->events->first()->status);
    }

    public function testWebhookEventsAreAppendedToTheTimeline()
    {
        config(['postmaster.persistence.record_events' => true]);

        $record = EmailMessage::create([
            'message_id' => 'postmark-timeline-1',
            'recipient'  => 'recipient@example.com',
            'status'     => EmailEvent::EVENT_SENT,
        ]);

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'postmark-timeline-1',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        $this->assertDatabaseCount('email_message_events', 1);

        $entry = $record->events->first();
        $this->assertSame(EmailEvent::EVENT_DELIVERED, $entry->status);
        $this->assertSame('Postmark', $entry->provider);
        $this->assertNotNull($entry->occurred_at);
    }

    public function testTimelineKeepsRepeatedEventsInChronologicalOrder()
    {
        config(['postmaster.persistence.record_events' => true]);

        $record = EmailMessage::create([
            'message_id' => 'postmark-timeline-2',
            'recipient'  => 'recipient@example.com',
            'status'     => EmailEvent::EVENT_SENT,
        ]);

        foreach (['2021-01-01T12:00:00Z', '2021-01-03T12:00:00Z'] as $at) {
            event(EmailEvent::create(new Postmark([
                'RecordType' => 'Open',
                'MessageID'  => 'postmark-timeline-2',
                'Recipient'  => 'recipient@example.com',
                'ReceivedAt' => $at,
            ])));
        }

        $events = $record->events;
        $this->assertCount(2, $events);
        $this->assertTrue($events->first()->occurred_at->lt($events->last()->occurred_at));
        $this->assertSame(EmailEvent::EVENT_OPENED, $record->fresh()->status);
    }

    public function testStaleEventDoesNotRegressTheSummaryButStillJoinsTheTimeline()
    {
        config(['postmaster.persistence.record_events' => true]);

        $record = EmailMessage::create([
            'message_id' => 'postmark-timeline-3',
            'recipient'  => 'recipient@example.com',
            'status'     => EmailEvent::EVENT_SENT,
        ]);

        // The delivery webhook arrives first...
        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'postmark-timeline-3',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-02T00:00:00Z',
        ])));

        // ...then an earlier "deferred" webhook arrives late.
        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'Transient',
            'MessageID'  => 'postmark-timeline-3',
            'Email'      => 'recipient@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $record->refresh();
        $this->assertSame(EmailEvent::EVENT_DELIVERED, $record->status);
        $this->assertCount(2, $record->events);
    }

    public function testPruneEventsCommandDeletesOldTimelineEvents()
    {
        config(['postmaster.persistence.prune_events_after_days' => 30]);

        $record = EmailMessage::create(['message_id' => 'postmark-timeline-4']);

        $old = EmailMessageEvent::create([
            'email_message_id' => $record->getKey(),
            'status'           => EmailEvent::EVENT_DELIVERED,
            'occurred_at'      => now()->subDays(60),
        ]);

        $recent = EmailMessageEvent::create([
            'email_message_id' => $record->getKey(),
            'status'           => EmailEvent::EVENT_OPENED,
            'occurred_at'      => now()->subDays(5),
        ]);

        $this->artisan('postmaster:prune-events')->assertSuccessful();

        $this->assertDatabaseMissing('email_message_events', ['id' => $old->getKey()]);
        $this->assertDatabaseHas('email_message_events', ['id' => $recent->getKey()]);
    }

    public function testAddressesAreNotTrackedByDefault()
    {
        Mail::raw('Hello', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertDatabaseCount('email_addresses', 0);
    }

    public function testSendingRecordsAnActiveAddress()
    {
        config(['postmaster.persistence.track_addresses' => true]);

        Mail::raw('Hello', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $address = EmailAddress::first();
        $this->assertSame('recipient@example.com', $address->address);
        $this->assertSame(EmailAddress::STATUS_ACTIVE, $address->status);
        $this->assertNotNull($address->last_event_at);
    }

    public function testHardBounceSuppressesTheAddress()
    {
        config(['postmaster.persistence.track_addresses' => true]);

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'm-suppress-1',
            'Email'      => 'bounce@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $address = EmailAddress::where('address', 'bounce@example.com')->first();
        $this->assertNotNull($address);
        $this->assertSame(EmailAddress::STATUS_SUPPRESSED, $address->status);
        $this->assertSame(EmailEvent::EVENT_BOUNCED, $address->reason);
        $this->assertNotNull($address->suppressed_at);
    }

    public function testComplaintSuppressesTheAddress()
    {
        config(['postmaster.persistence.track_addresses' => true]);

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'SpamComplaint',
            'MessageID'  => 'm-suppress-2',
            'Email'      => 'spam@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $address = EmailAddress::where('address', 'spam@example.com')->first();
        $this->assertSame(EmailAddress::STATUS_SUPPRESSED, $address->status);
        $this->assertSame(EmailEvent::EVENT_COMPLAINED, $address->reason);
    }

    public function testDroppedSuppressesTheAddress()
    {
        config(['postmaster.persistence.track_addresses' => true]);

        event(EmailEvent::create(new SendGrid([
            'sg_message_id' => 'sg-1',
            'smtp-id'       => '<sg-1>',
            'event'         => 'dropped',
            'email'         => 'dropped@example.com',
            'timestamp'     => strtotime('2021-01-01T00:00:00Z'),
        ])));

        $address = EmailAddress::where('address', 'dropped@example.com')->first();
        $this->assertSame(EmailAddress::STATUS_SUPPRESSED, $address->status);
        $this->assertSame(EmailEvent::EVENT_DROPPED, $address->reason);
    }

    public function testSoftBounceDoesNotSuppressTheAddress()
    {
        config(['postmaster.persistence.track_addresses' => true]);

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'SoftBounce',
            'MessageID'  => 'm-suppress-3',
            'Email'      => 'soft@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $address = EmailAddress::where('address', 'soft@example.com')->first();
        $this->assertNotNull($address);
        $this->assertSame(EmailAddress::STATUS_ACTIVE, $address->status);
    }

    public function testSuppressionIsStickyAcrossLaterEvents()
    {
        config(['postmaster.persistence.track_addresses' => true]);

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'm-sticky-1',
            'Email'      => 'sticky@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        // A delivery webhook arriving afterward must not revive the address.
        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'm-sticky-2',
            'Recipient'   => 'sticky@example.com',
            'DeliveredAt' => '2021-02-01T00:00:00Z',
        ])));

        $address = EmailAddress::where('address', 'sticky@example.com')->first();
        $this->assertSame(EmailAddress::STATUS_SUPPRESSED, $address->status);
        $this->assertSame(EmailEvent::EVENT_BOUNCED, $address->reason);
    }

    public function testIsSuppressedReportsAddressStatus()
    {
        config(['postmaster.persistence.track_addresses' => true]);

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'm-check-1',
            'Email'      => 'known@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $this->assertTrue(Postmaster::isSuppressed('known@example.com'));
        $this->assertTrue(Postmaster::isSuppressed('KNOWN@EXAMPLE.COM'));
        $this->assertFalse(Postmaster::isSuppressed('never-seen@example.com'));
    }

    public function testManualSuppressionCanBeAppliedAndLifted()
    {
        Postmaster::suppress('manual@example.com');

        $this->assertTrue(Postmaster::isSuppressed('manual@example.com'));
        $this->assertSame(
            EmailAddress::REASON_MANUAL,
            EmailAddress::where('address', 'manual@example.com')->first()->reason
        );

        Postmaster::unsuppress('manual@example.com');

        $this->assertFalse(Postmaster::isSuppressed('manual@example.com'));
    }

    public function testAddressMatchingIsCaseInsensitive()
    {
        config(['postmaster.persistence.track_addresses' => true]);

        Mail::raw('Hello', function ($message) {
            $message->to('Mixed@Example.com')->subject('Greetings');
        });

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'm-case-1',
            'Email'      => 'mixed@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $this->assertDatabaseCount('email_addresses', 1);
        $this->assertSame(EmailAddress::STATUS_SUPPRESSED, EmailAddress::first()->status);
    }

    public function testVerifyDetectsProviderFromMailTransport()
    {
        config([
            'mail.default'           => 'postmark',
            'mail.mailers.postmark'  => ['transport' => 'postmark'],
        ]);

        $this->artisan('postmaster:verify')
            ->expectsConfirmation('Detected the "postmark" provider from the "postmark" mail transport. Verify that one?', 'yes')
            ->expectsOutputToContain('local address')
            ->expectsConfirmation('Have you set that webhook URL in your postmark dashboard?', 'no')
            ->assertExitCode(1);

        $this->assertDatabaseCount('email_messages', 0);
    }

    public function testVerifyGuessesProviderFromSmtpHost()
    {
        config([
            'mail.default'      => 'smtp',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.sendgrid.net'],
        ]);

        $this->artisan('postmaster:verify')
            ->expectsConfirmation('Detected the "sendgrid" provider from the SMTP host "smtp.sendgrid.net". Verify that one?', 'yes')
            ->expectsConfirmation('Have you set that webhook URL in your sendgrid dashboard?', 'no')
            ->assertExitCode(1);
    }

    public function testVerifySendsTheTestEmailOnceConfirmed()
    {
        config(['cache.default' => 'array']);

        $this->artisan('postmaster:verify')
            ->expectsChoice('Which provider are you verifying?', 'postmark', ['sendgrid', 'postmark', 'mailgun', 'ses', 'resend'])
            ->expectsConfirmation('Have you set that webhook URL in your postmark dashboard?', 'yes')
            ->expectsQuestion('Send the test email to which address? (use a real inbox you can check)', 'tester@example.com')
            ->expectsOutputToContain('per-process')
            ->expectsOutputToContain('Test email sent to tester@example.com.');

        $this->assertDatabaseHas('email_messages', ['recipient' => 'tester@example.com']);
    }

    public function testVerificationEventsAreRelayedToTheWatchedMessage()
    {
        Cache::put(RelayVerificationEvent::WATCHING_KEY, 'watched-message', now()->addMinutes(5));

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'watched-message',
            'Recipient'   => 'r@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        // An event for any other message must not be relayed.
        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'a-different-message',
            'Recipient'   => 'other@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        $relayed = Cache::get(RelayVerificationEvent::EVENTS_KEY);
        $this->assertIsArray($relayed);
        $this->assertCount(1, $relayed);
        $this->assertSame(EmailEvent::EVENT_DELIVERED, $relayed[0]['status']);
    }
}
