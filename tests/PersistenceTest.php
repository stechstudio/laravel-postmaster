<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Listeners\RelayVerificationEvent;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Providers\Postmark\Adapter as Postmark;
use STS\Postmaster\Providers\SendGrid\Adapter as SendGrid;
use STS\Postmaster\Tests\Stubs\DeclaredMail;
use STS\Postmaster\Tests\Stubs\DropInMailMessageNotification;
use STS\Postmaster\Tests\Stubs\FullMail;
use STS\Postmaster\Tests\Stubs\Order;
use STS\Postmaster\Tests\Stubs\OrderConfirmationMail;
use STS\Postmaster\Tests\Stubs\RelatedNotification;
use STS\Postmaster\Tests\Stubs\ScopedEmailMessage;
use STS\Postmaster\Tests\Stubs\Tenant;
use STS\Postmaster\Tests\Stubs\TrackedMail;
use STS\Postmaster\Tests\Stubs\CustomTrackedMailMessage;
use STS\Postmaster\Tests\Stubs\User;
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
        $this->assertSame('recipient@example.com', $record->to_address);
        $this->assertSame('Greetings', $record->subject);
        // Tests run through Laravel's `array` mailer (no real transport), so
        // the recorded row is captured rather than sent — see
        // statusForCurrentTransport() on the listener.
        $this->assertSame(EmailEvent::STATUS_CAPTURED, $record->status);
        $this->assertNotEmpty($record->provider_message_id);
        $this->assertNotNull($record->sent_at);
    }

    public function testWebhookEventUpdatesTheExistingRecord()
    {
        $record = EmailMessage::create([
            'provider_message_id' => 'postmark-message-1',
            'to_address'  => 'recipient@example.com',
            'status'     => EmailEvent::STATUS_SENT,
        ]);

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'postmark-message-1',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        $this->assertDatabaseCount('email_messages', 1);

        $record->refresh();
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $record->status);
        $this->assertSame('Postmark', $record->provider);
        $this->assertNotNull($record->last_event_at);
    }

    public function testEmailEventCarriesTheCorrelatedMessageRecord()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        $record = EmailMessage::create([
            'provider_message_id'   => 'postmark-message-9',
            'to_address'    => 'recipient@example.com',
            'status'       => EmailEvent::STATUS_SENT,
            'related_type' => $order->getMorphClass(),
            'related_id'   => $order->getKey(),
        ]);

        $captured = null;

        Event::listen(EmailEvent::class, function (EmailEvent $event) use (&$captured) {
            $captured = $event->emailMessage();
        });

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'postmark-message-9',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        $this->assertNotNull($captured);
        $this->assertTrue($captured->is($record));

        // The headline use case: walk back to the originating app model.
        $this->assertTrue($captured->related->is($order));

        // The record handed to the listener already reflects this event.
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $captured->status);
    }

    public function testEmailEventMessageIsNullWhenNoRecordCorrelates()
    {
        $captured = 'unset';

        Event::listen(EmailEvent::class, function (EmailEvent $event) use (&$captured) {
            $captured = $event->emailMessage();
        });

        // No message id on the payload — nothing to correlate to.
        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Delivery',
            'Recipient'  => 'recipient@example.com',
        ])));

        $this->assertNull($captured);
    }

    public function testBounceEventRecordsTheBounceType()
    {
        EmailMessage::create([
            'provider_message_id' => 'postmark-message-2',
            'to_address'  => 'recipient@example.com',
            'status'     => EmailEvent::STATUS_SENT,
        ]);

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'postmark-message-2',
            'Email'      => 'recipient@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $record = EmailMessage::where('provider_message_id', 'postmark-message-2')->first();
        $this->assertSame(EmailEvent::STATUS_BOUNCED, $record->status);
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

    public function testDeclaredAssociationsAreRecordedFromAMailable()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        Mail::to('recipient@example.com')->send(new DeclaredMail(order: $order, account: 42));

        $record = EmailMessage::first();
        $this->assertSame($order->getMorphClass(), $record->related_type);
        $this->assertSame((string) $order->getKey(), (string) $record->related_id);
        $this->assertSame('42', (string) $record->tenant_id);
    }

    public function testDeclaredAssociationsAreOptional()
    {
        Mail::to('recipient@example.com')->send(new DeclaredMail);

        $record = EmailMessage::first();
        $this->assertNull($record->related_type);
        $this->assertNull($record->tenant_id);
    }

    public function testDeclaredTrackingControlsContentStorage()
    {
        // Global storage on, but this mailable opts out via Tracking.
        config(['postmaster.persistence.store_content' => true]);
        Mail::to('out@example.com')->send(new DeclaredMail(store: false));
        $this->assertNull(EmailMessage::where('to_address', 'out@example.com')->first()->html_body);

        // Global storage off, but this mailable opts in.
        config(['postmaster.persistence.store_content' => false]);
        Mail::to('in@example.com')->send(new DeclaredMail(store: true));
        $this->assertSame('<p>declared</p>', EmailMessage::where('to_address', 'in@example.com')->first()->html_body);
    }

    public function testGlobalStoreContentResolverControlsContentStorage()
    {
        // Global flag off; the resolver decides per message, keying off the
        // subject — the Fortify-style "don't store secrets" case.
        config(['postmaster.persistence.store_content' => false]);

        Postmaster::storeContentWhen(
            fn ($message) => ! str_contains((string) $message->getSubject(), 'Reset Password')
        );

        Mail::to('keep@example.com')->send(new FullMail);

        Mail::raw('secret reset link', function ($message) {
            $message->to('secret@example.com')->subject('Reset Password');
        });

        $this->assertSame('<p>Body</p>', EmailMessage::where('to_address', 'keep@example.com')->first()->html_body);
        $this->assertNull(EmailMessage::where('to_address', 'secret@example.com')->first()->text_body);
    }

    public function testPerMessageOverrideBeatsStoreContentResolver()
    {
        // Resolver says store everything, but a per-message dontStoreContent()
        // (here via Tracking) still wins.
        config(['postmaster.persistence.store_content' => false]);
        Postmaster::storeContentWhen(fn () => true);

        Mail::to('out@example.com')->send(new DeclaredMail(store: false));

        $this->assertNull(EmailMessage::where('to_address', 'out@example.com')->first()->html_body);
    }

    public function testStoreContentResolverOverridesConfigFlag()
    {
        // Global flag on, but the resolver opts this message out.
        config(['postmaster.persistence.store_content' => true]);
        Postmaster::storeContentWhen(fn () => false);

        Mail::to('out@example.com')->send(new FullMail);

        $this->assertNull(EmailMessage::where('to_address', 'out@example.com')->first()->html_body);
    }

    public function testDeclaredTagsAreRecordedAndQueryable()
    {
        Mail::to('recipient@example.com')->send(new DeclaredMail(labels: ['billing', 'invoice']));

        $this->assertSame(['billing', 'invoice'], EmailMessage::first()->tags);
        $this->assertCount(1, EmailMessage::taggedWith('billing')->get());
        $this->assertCount(0, EmailMessage::taggedWith('marketing')->get());
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
        $this->assertSame(EmailEvent::STATUS_CAPTURED, $order->emailMessages->first()->status);
        $this->assertTrue($order->is($order->emailMessages->first()->related));
    }

    public function testRecipientModelIsRecordedFromMailableDeclaration()
    {
        Schema::create('users', fn ($table) => $table->id());
        $user = User::create();

        Mail::to('alice@example.com')->send(new DeclaredMail(user: $user));

        $record = EmailMessage::first();
        $this->assertSame($user->getMorphClass(), $record->recipient_type);
        $this->assertSame((string) $user->getKey(), (string) $record->recipient_id);
    }

    public function testRecipientModelIsRecordedFromTheGlobalResolver()
    {
        Schema::create('users', fn ($table) => $table->id());
        $user = User::create();

        Postmaster::resolveRecipientUsing(fn ($address) => $address === 'alice@example.com' ? $user : null);

        Mail::to('alice@example.com')->send(new DeclaredMail);

        $record = EmailMessage::first();
        $this->assertSame($user->getMorphClass(), $record->recipient_type);
        $this->assertSame((string) $user->getKey(), (string) $record->recipient_id);
    }

    public function testExplicitForRecipientOverridesTheResolver()
    {
        Schema::create('users', fn ($table) => $table->id());
        $declared = User::create();
        $resolved = User::create();

        Postmaster::resolveRecipientUsing(fn () => $resolved);

        Mail::to('alice@example.com')->send(new DeclaredMail(user: $declared));

        $record = EmailMessage::first();
        $this->assertSame((string) $declared->getKey(), (string) $record->recipient_id);
    }

    public function testRecordedRowIsMarkedLoggedWhenTheLogDriverIsTheDefault()
    {
        // Local dev's typical MAIL_MAILER=log — no real provider, no
        // webhook to follow. The row is marked terminal so the dashboard
        // does not keep showing it as "sent, waiting on the provider."
        config()->set('mail.default', 'log');
        config()->set('mail.mailers.log', ['transport' => 'log']);

        Mail::raw('Hello there', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $record = EmailMessage::first();
        $this->assertSame(EmailEvent::STATUS_LOGGED, $record->status);
        $this->assertTrue($record->isLogged());
    }

    public function testResolveRecipientByEmailLooksUpTheModelByItsEmailColumn()
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
        });

        $user = User::create(['email' => 'alice@example.com']);

        Postmaster::resolveRecipientByEmail(User::class);

        Mail::to('alice@example.com')->send(new DeclaredMail);

        $record = EmailMessage::first();
        $this->assertSame((string) $user->getKey(), (string) $record->recipient_id);
        $this->assertSame($user->getMorphClass(), $record->recipient_type);
    }

    public function testResolveRecipientByEmailNormalizesTheAddressBeforeMatching()
    {
        // Webhook arrives with mixed-case from a sloppy provider; the user
        // row was stored lowercase. The resolver should still match.
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
        });

        $user = User::create(['email' => 'alice@example.com']);

        Postmaster::resolveRecipientByEmail(User::class);

        Mail::to('Alice@Example.com')->send(new DeclaredMail);

        $record = EmailMessage::first();
        $this->assertSame((string) $user->getKey(), (string) $record->recipient_id);
    }

    public function testResolveRecipientByEmailAcceptsAColumnOverride()
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('contact_email');
        });

        $user = User::create(['contact_email' => 'alice@example.com']);

        Postmaster::resolveRecipientByEmail(User::class, column: 'contact_email');

        Mail::to('alice@example.com')->send(new DeclaredMail);

        $record = EmailMessage::first();
        $this->assertSame((string) $user->getKey(), (string) $record->recipient_id);
    }

    public function testIsEmailRecipientLoadsEveryEmailSentToTheModel()
    {
        Schema::create('users', fn ($table) => $table->id());
        Schema::create('orders', fn ($table) => $table->id());
        $alice = User::create();
        $bob   = User::create();
        $order = Order::create();

        // An email about an Order, sent to Alice — Alice should still find it.
        Mail::to('alice@example.com')->send(new DeclaredMail(order: $order, user: $alice));
        // A second email to Alice, unrelated to anything.
        Mail::to('alice@example.com')->send(new DeclaredMail(user: $alice));
        // One to Bob, to prove the scope is per-user.
        Mail::to('bob@example.com')->send(new DeclaredMail(user: $bob));

        $this->assertCount(2, $alice->emailMessages);
        $this->assertCount(1, $bob->emailMessages);
        $this->assertTrue($alice->is($alice->emailMessages->first()->recipient));

        // HasEmailMessages still scopes the Order to its own emails, untouched
        // by the recipient-side link.
        $this->assertCount(1, $order->emailMessages);
    }

    public function testIsEmailRecipientReportsLatestFailureStatus()
    {
        Schema::create('users', fn ($table) => $table->id());
        $user = User::create();

        Mail::to('alice@example.com')->send(new DeclaredMail(user: $user));
        $this->assertFalse($user->emailDeliveryFailed());

        $user->latestEmailMessage()->update(['status' => EmailEvent::STATUS_BOUNCED]);
        $this->assertTrue($user->fresh()->emailDeliveryFailed());
    }

    public function testRecipientHeadersAreStrippedBeforeSending()
    {
        Schema::create('users', fn ($table) => $table->id());
        $user = User::create();

        Mail::to('alice@example.com')->send(new DeclaredMail(user: $user));

        $messages = Mail::getSymfonyTransport()->messages();
        $headers = $messages[0]->getOriginalMessage()->getHeaders();

        // The courier headers must never travel on the wire.
        $this->assertFalse($headers->has('X-Postmaster-Recipient-Type'));
        $this->assertFalse($headers->has('X-Postmaster-Recipient-Id'));
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

    public function testNotificationCanAssociateViaTheDropInMailMessage()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        NotificationFacade::route('mail', 'recipient@example.com')
            ->notify(new DropInMailMessageNotification($order));

        $record = EmailMessage::first();
        $this->assertNotNull($record);
        $this->assertSame($order->getMorphClass(), $record->related_type);
        $this->assertSame((string) $order->getKey(), (string) $record->related_id);
        $this->assertSame('7', (string) $record->tenant_id);
    }

    public function testWithTrackingTraitWorksOnACustomMailMessageSubclass()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        $message = (new CustomTrackedMailMessage)->relatedTo($order);

        $email = new Email;
        foreach ($message->callbacks as $callback) {
            $callback($email);
        }

        $this->assertSame(
            $order->getMorphClass(),
            $email->getHeaders()->get('X-Postmaster-Related-Type')->getBodyAsString()
        );
    }

    public function testDontStoreContentIsAvailableFluentlyOnAMailMessage()
    {
        $message = (new CustomTrackedMailMessage)->dontStoreContent();

        $email = new Email;
        foreach ($message->callbacks as $callback) {
            $callback($email);
        }

        $this->assertSame('0', $email->getHeaders()->get('X-Postmaster-Store-Content')->getBodyAsString());
    }

    public function testTenantHeaderIsStrippedBeforeSending()
    {
        Mail::to('recipient@example.com')->send(new TrackedMail(tenant: 42));

        $headers = Mail::getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHeaders();
        $this->assertFalse($headers->has('X-Postmaster-Tenant'));
    }

    public function testForTenantScopeFiltersByTenant()
    {
        EmailMessage::create(['provider_message_id' => 'a', 'tenant_id' => 1]);
        EmailMessage::create(['provider_message_id' => 'b', 'tenant_id' => 2]);
        EmailMessage::create(['provider_message_id' => 'c', 'tenant_id' => 1]);

        $this->assertCount(2, EmailMessage::forTenant(1)->get());
        $this->assertCount(1, EmailMessage::forTenant(2)->get());
    }

    public function testTenantRelationResolves()
    {
        Schema::create('tenants', fn ($table) => $table->id());
        config(['postmaster.persistence.tenant_model' => Tenant::class]);
        $tenant = Tenant::create();

        $record = EmailMessage::create(['provider_message_id' => 'a', 'tenant_id' => $tenant->getKey()]);

        $this->assertTrue($tenant->is($record->tenant));
    }

    public function testMultiRecipientSendRecordsOneRowPerEnvelopeRecipient()
    {
        Mail::to(['alice@example.com', 'bob@example.com'])
            ->cc('ops@example.com')
            ->bcc('audit@example.com')
            ->send(new FullMail);

        // 2 To + 1 Cc + 1 Bcc = 4 rows, all sharing the provider message id.
        $this->assertCount(4, EmailMessage::all());

        $providerIds = EmailMessage::distinct()->pluck('provider_message_id');
        $this->assertCount(1, $providerIds);

        $this->assertSame(2, EmailMessage::where('recipient_role', 'to')->count());
        $this->assertSame(1, EmailMessage::where('recipient_role', 'cc')->count());
        $this->assertSame(1, EmailMessage::where('recipient_role', 'bcc')->count());

        // Addresses lowercased on write for case-insensitive correlation.
        $this->assertTrue(EmailMessage::where('to_address', 'alice@example.com')->exists());
    }

    public function testWebhookLandsOnTheRightRowForMultiRecipientSends()
    {
        Mail::to(['alice@example.com', 'bob@example.com'])->send(new FullMail);

        $providerId = EmailMessage::first()->provider_message_id;

        // Bob bounces; Alice doesn't. The wrong-attribution bug we set out
        // to fix was that this would flip Alice's row to bounced. Per-row
        // correlation by (provider_message_id, to_address) keeps each row
        // accurate.
        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => $providerId,
            'Email'      => 'bob@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        $alice = EmailMessage::where('to_address', 'alice@example.com')->first();
        $bob   = EmailMessage::where('to_address', 'bob@example.com')->first();

        $this->assertTrue($alice->isCaptured());
        $this->assertTrue($bob->isBounced());
    }

    public function testWebhookCorrelationIsCaseInsensitive()
    {
        Mail::to('Alice@Example.COM')->send(new FullMail);

        $this->assertSame('alice@example.com', EmailMessage::first()->to_address);

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => EmailMessage::first()->provider_message_id,
            'Recipient'   => 'ALICE@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        // Same provider id + same address (different casing) → same row.
        $this->assertCount(1, EmailMessage::all());
        $this->assertTrue(EmailMessage::first()->isDelivered());
    }

    public function testTrackingRecipientsMapBindsModelsPerAddress()
    {
        Schema::create('users', fn ($table) => $table->id());
        $alice = User::create();
        $bob   = User::create();

        Mail::to(['alice@example.com', 'bob@example.com'])
            ->send(new DeclaredMail(recipientMap: [
                'alice@example.com' => $alice,
                'bob@example.com'   => $bob,
            ]));

        $aliceRow = EmailMessage::where('to_address', 'alice@example.com')->first();
        $bobRow   = EmailMessage::where('to_address', 'bob@example.com')->first();

        $this->assertSame((string) $alice->getKey(), (string) $aliceRow->recipient_id);
        $this->assertSame((string) $bob->getKey(),   (string) $bobRow->recipient_id);
    }

    public function testRecipientsMapFallsThroughToResolverForUnmappedAddresses()
    {
        Schema::create('users', fn ($table) => $table->id());
        $alice    = User::create();
        $stranger = User::create();

        // Alice is in the map; bob is not — but the resolver knows him.
        Postmaster::resolveRecipientUsing(fn ($address) => $address === 'bob@example.com' ? $stranger : null);

        Mail::to(['alice@example.com', 'bob@example.com'])
            ->send(new DeclaredMail(recipientMap: ['alice@example.com' => $alice]));

        $this->assertSame(
            (string) $alice->getKey(),
            (string) EmailMessage::where('to_address', 'alice@example.com')->first()->recipient_id
        );
        $this->assertSame(
            (string) $stranger->getKey(),
            (string) EmailMessage::where('to_address', 'bob@example.com')->first()->recipient_id
        );
    }

    public function testSingularRecipientDeclarationOnlyAppliesToThePrimaryToRow()
    {
        Schema::create('users', fn ($table) => $table->id());
        $alice = User::create();

        // Alice is declared singularly; bob is not in any map. With no
        // resolver registered, bob's row has no recipient model.
        Mail::to(['alice@example.com', 'bob@example.com'])
            ->send(new DeclaredMail(user: $alice));

        $this->assertSame(
            (string) $alice->getKey(),
            (string) EmailMessage::where('to_address', 'alice@example.com')->first()->recipient_id
        );
        $this->assertNull(EmailMessage::where('to_address', 'bob@example.com')->first()->recipient_id);
    }

    public function testStatusPredicatesOnEmailMessage()
    {
        $record = EmailMessage::create(['status' => EmailEvent::STATUS_BOUNCED]);

        $this->assertTrue($record->isBounced());
        $this->assertTrue($record->isFailed());          // bounced rolls up into failed
        $this->assertFalse($record->isDelivered());
        $this->assertFalse($record->isSandboxed());

        $record->update(['status' => EmailEvent::STATUS_DELIVERED]);
        $this->assertTrue($record->fresh()->isDelivered());
        $this->assertFalse($record->fresh()->isFailed());
    }

    public function testEmailDeliveryFailedNotificationRendersTheKeyDetails()
    {
        $event = EmailEvent::create(new Postmark([
            'RecordType'  => 'Bounce',
            'Type'        => 'HardBounce',
            'MessageID'   => 'm1',
            'Email'       => 'alice@example.com',
            'BouncedAt'   => '2021-01-01T00:00:00Z',
            'Description' => 'Mailbox does not exist.',
        ]));

        $notification = new \STS\Postmaster\Notifications\EmailDeliveryFailed($event);
        $mail = $notification->toMail((object) []);

        $this->assertSame('Email delivery failed: alice@example.com', $mail->subject);
        $this->assertSame('error', $mail->level);
        $rendered = implode("\n", $mail->introLines);
        $this->assertStringContainsString('alice@example.com', $rendered);
        $this->assertStringContainsString('bounced', $rendered);
        $this->assertStringContainsString('Postmark', $rendered);
    }

    public function testStatusPredicatesOnEmailEvent()
    {
        $event = EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'm1',
            'Email'      => 'r@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ]));

        $this->assertTrue($event->isBounced());
        $this->assertTrue($event->isFailed());
        $this->assertFalse($event->isDelivered());
    }

    public function testUseTenantModelConfiguresTheTenantRelationship()
    {
        Schema::create('tenants', fn ($table) => $table->id());
        Postmaster::useTenantModel(Tenant::class);
        $tenant = Tenant::create();

        $record = EmailMessage::create(['provider_message_id' => 'a', 'tenant_id' => $tenant->getKey()]);

        $this->assertTrue($tenant->is($record->tenant));
    }

    public function testUseEmailModelSettersSetTheConfigKeys()
    {
        Postmaster::useEmailMessageModel('App\\Models\\MyEmail');
        Postmaster::useEmailActivityModel('App\\Models\\MyEvent');
        Postmaster::useEmailAddressModel('App\\Models\\MyAddress');

        $this->assertSame('App\\Models\\MyEmail',   config('postmaster.persistence.message_model'));
        $this->assertSame('App\\Models\\MyEvent',   config('postmaster.persistence.activity_model'));
        $this->assertSame('App\\Models\\MyAddress', config('postmaster.persistence.address_model'));
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
            'provider_message_id'   => 'old',
            'status'       => EmailEvent::STATUS_SENT,
            'html_body'    => '<p>old</p>',
            'from_address' => 'sender@example.com',
        ]);
        $old->created_at = now()->subDays(60);
        $old->save();

        $recent = EmailMessage::create([
            'provider_message_id' => 'recent',
            'html_body'  => '<p>recent</p>',
        ]);

        $this->artisan('postmaster:prune', ['--content' => true])->assertSuccessful();

        $old->refresh();
        $this->assertNull($old->html_body);
        $this->assertNull($old->from_address);
        $this->assertSame(EmailEvent::STATUS_SENT, $old->status);

        $this->assertSame('<p>recent</p>', $recent->refresh()->html_body);
    }

    public function testPruneContentDryRunLeavesContentInPlace()
    {
        config(['postmaster.persistence.prune_content_after_days' => 30]);

        $old = EmailMessage::create([
            'provider_message_id' => 'old',
            'status'              => EmailEvent::STATUS_SENT,
            'html_body'           => '<p>old</p>',
            'from_address'        => 'sender@example.com',
        ]);
        $old->created_at = now()->subDays(60);
        $old->save();

        $this->artisan('postmaster:prune', ['--content' => true, '--dry-run' => true])->assertSuccessful();

        // Past the retention window, but a dry run must not touch the row.
        $old->refresh();
        $this->assertSame('<p>old</p>', $old->html_body);
        $this->assertSame('sender@example.com', $old->from_address);
    }

    public function testStatusScopesFilterRecords()
    {
        EmailMessage::create(['provider_message_id' => 'a', 'status' => EmailEvent::STATUS_DELIVERED]);
        EmailMessage::create(['provider_message_id' => 'b', 'status' => EmailEvent::STATUS_BOUNCED]);
        EmailMessage::create(['provider_message_id' => 'c', 'status' => EmailEvent::STATUS_DELIVERED]);

        $this->assertCount(2, EmailMessage::delivered()->get());
        $this->assertCount(1, EmailMessage::bounced()->get());
        $this->assertCount(1, EmailMessage::withStatus(EmailEvent::STATUS_BOUNCED)->get());
    }

    public function testFailedScopeCoversBouncedDroppedAndComplained()
    {
        EmailMessage::create(['provider_message_id' => 'a', 'status' => EmailEvent::STATUS_DELIVERED]);
        EmailMessage::create(['provider_message_id' => 'b', 'status' => EmailEvent::STATUS_BOUNCED]);
        EmailMessage::create(['provider_message_id' => 'c', 'status' => EmailEvent::STATUS_DROPPED]);
        EmailMessage::create(['provider_message_id' => 'd', 'status' => EmailEvent::STATUS_COMPLAINED]);
        EmailMessage::create(['provider_message_id' => 'e', 'status' => EmailEvent::STATUS_SENT]);

        $failed = EmailMessage::failed()->pluck('provider_message_id')->all();

        sort($failed);
        $this->assertSame(['b', 'c', 'd'], $failed);
    }

    public function testRelatedModelReadsItsLatestEmailAndFailureState()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        $this->assertNull($order->latestEmailMessage());
        $this->assertFalse($order->emailDeliveryFailed());

        EmailMessage::create([
            'provider_message_id'   => 'first',
            'status'       => EmailEvent::STATUS_DELIVERED,
            'related_type' => $order->getMorphClass(),
            'related_id'   => $order->getKey(),
        ]);
        $latest = EmailMessage::create([
            'provider_message_id'   => 'reminder',
            'status'       => EmailEvent::STATUS_BOUNCED,
            'related_type' => $order->getMorphClass(),
            'related_id'   => $order->getKey(),
        ]);

        $this->assertTrue($latest->is($order->latestEmailMessage()));
        $this->assertTrue($order->emailDeliveryFailed());
    }

    public function testWebhookCorrelationIgnoresGlobalScopes()
    {
        config(['postmaster.persistence.message_model' => ScopedEmailMessage::class]);

        EmailMessage::create([
            'provider_message_id' => 'scoped-message-1',
            'to_address'  => 'recipient@example.com',
            'status'     => EmailEvent::STATUS_SENT,
        ]);

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'scoped-message-1',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        $this->assertDatabaseCount('email_messages', 1);
        $this->assertSame(
            EmailEvent::STATUS_DELIVERED,
            EmailMessage::where('provider_message_id', 'scoped-message-1')->first()->status
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

        $record = EmailMessage::where('provider_message_id', 'never-seen-before')->first();
        $this->assertNotNull($record);
        $this->assertSame('recipient@example.com', $record->to_address);
        $this->assertSame(EmailEvent::STATUS_BOUNCED, $record->status);
        $this->assertSame(EmailEvent::BOUNCE_HARD, $record->bounce_type);
    }

    public function testDuplicateWebhookEventsAreDeduped()
    {
        config(['postmaster.persistence.record_events' => true]);

        $record = EmailMessage::create([
            'provider_message_id' => 'postmark-dup',
            'to_address'           => 'recipient@example.com',
            'status'              => EmailEvent::STATUS_SENT,
        ]);

        $payload = [
            'RecordType'  => 'Delivery',
            'MessageID'   => 'postmark-dup',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ];

        // The same webhook is delivered twice (a provider retry on a
        // transient failure). The timeline must not double up.
        event(EmailEvent::create(new Postmark($payload)));
        event(EmailEvent::create(new Postmark($payload)));

        // One delivery row from the first webhook; the retry is dropped.
        $this->assertCount(1, $record->refresh()->activity);
    }

    public function testClickEventStoresTheClickedUrlOnTheTimeline()
    {
        config(['postmaster.persistence.record_events' => true]);

        EmailMessage::create([
            'provider_message_id' => 'postmark-click',
            'to_address'           => 'recipient@example.com',
            'status'              => EmailEvent::STATUS_SENT,
        ]);

        event(EmailEvent::create(new Postmark([
            'RecordType'   => 'Click',
            'MessageID'    => 'postmark-click',
            'Recipient'    => 'recipient@example.com',
            'OriginalLink' => 'https://example.com/promo',
            'ReceivedAt'   => '2021-01-01T00:00:00Z',
        ])));

        $event = EmailActivity::where('status', EmailEvent::STATUS_CLICKED)->first();
        $this->assertNotNull($event);
        $this->assertSame('https://example.com/promo', $event->url);
    }

    public function testTimelineIsRecordedByDefault()
    {
        Mail::raw('Hello', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        // Timeline recording follows persistence; the send seeds one event.
        $this->assertDatabaseCount('email_messages', 1);
        $this->assertDatabaseCount('email_activity', 1);
    }

    public function testTimelineRecordingCanBeDisabled()
    {
        config(['postmaster.persistence.record_events' => false]);

        Mail::raw('Hello', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertDatabaseCount('email_messages', 1);
        $this->assertDatabaseCount('email_activity', 0);
    }

    public function testTheSendSeedsTheTimeline()
    {
        config(['postmaster.persistence.record_events' => true]);

        Mail::raw('Hello', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $record = EmailMessage::first();
        $this->assertCount(1, $record->activity);
        $this->assertSame(EmailEvent::STATUS_CAPTURED, $record->activity->first()->status);
    }

    public function testWebhookEventsAreAppendedToTheTimeline()
    {
        config(['postmaster.persistence.record_events' => true]);

        $record = EmailMessage::create([
            'provider_message_id' => 'postmark-timeline-1',
            'to_address'  => 'recipient@example.com',
            'status'     => EmailEvent::STATUS_SENT,
        ]);

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'postmark-timeline-1',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
        ])));

        $this->assertDatabaseCount('email_activity', 1);

        $entry = $record->activity->first();
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $entry->status);
        $this->assertSame('Postmark', $entry->provider);
        $this->assertNotNull($entry->occurred_at);
    }

    public function testTimelineKeepsRepeatedEventsInChronologicalOrder()
    {
        config(['postmaster.persistence.record_events' => true]);

        $record = EmailMessage::create([
            'provider_message_id' => 'postmark-timeline-2',
            'to_address'  => 'recipient@example.com',
            'status'     => EmailEvent::STATUS_SENT,
        ]);

        foreach (['2021-01-01T12:00:00Z', '2021-01-03T12:00:00Z'] as $at) {
            event(EmailEvent::create(new Postmark([
                'RecordType' => 'Open',
                'MessageID'  => 'postmark-timeline-2',
                'Recipient'  => 'recipient@example.com',
                'ReceivedAt' => $at,
            ])));
        }

        $events = $record->activity;
        $this->assertCount(2, $events);
        $this->assertTrue($events->first()->occurred_at->lt($events->last()->occurred_at));
        $this->assertSame(EmailEvent::STATUS_OPENED, $record->fresh()->status);
    }

    public function testStaleEventDoesNotRegressTheSummaryButStillJoinsTheTimeline()
    {
        config(['postmaster.persistence.record_events' => true]);

        $record = EmailMessage::create([
            'provider_message_id' => 'postmark-timeline-3',
            'to_address'  => 'recipient@example.com',
            'status'     => EmailEvent::STATUS_SENT,
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
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $record->status);
        $this->assertCount(2, $record->activity);
    }

    public function testPruneRemovesRoutineTimelineEventsPastRetention()
    {
        config(['postmaster.persistence.prune_routine_activity_after_days' => 30]);

        $record = EmailMessage::create(['provider_message_id' => 'postmark-timeline-4']);

        $old = EmailActivity::create([
            'email_message_id' => $record->getKey(),
            'status'           => EmailEvent::STATUS_DELIVERED,
            'occurred_at'      => now()->subDays(60),
        ]);

        $recent = EmailActivity::create([
            'email_message_id' => $record->getKey(),
            'status'           => EmailEvent::STATUS_OPENED,
            'occurred_at'      => now()->subDays(5),
        ]);

        $this->artisan('postmaster:prune', ['--activity' => true])->assertSuccessful();

        $this->assertDatabaseMissing('email_activity', ['id' => $old->getKey()]);
        $this->assertDatabaseHas('email_activity', ['id' => $recent->getKey()]);
    }

    public function testPruneKeepsFailureEventsLongerThanRoutineEvents()
    {
        // Routine events tidied at 30 days; failures get a 365-day window
        // — a hard bounce six months ago is still useful evidence.
        config([
            'postmaster.persistence.prune_routine_activity_after_days' => 30,
            'postmaster.persistence.prune_failed_activity_after_days'  => 365,
        ]);

        $record = EmailMessage::create(['provider_message_id' => 'rec']);

        $oldDelivery = EmailActivity::create([
            'email_message_id' => $record->getKey(),
            'status'           => EmailEvent::STATUS_DELIVERED,
            'occurred_at'      => now()->subDays(60),
        ]);
        $oldBounce = EmailActivity::create([
            'email_message_id' => $record->getKey(),
            'status'           => EmailEvent::STATUS_BOUNCED,
            'occurred_at'      => now()->subDays(60),
        ]);
        $ancientBounce = EmailActivity::create([
            'email_message_id' => $record->getKey(),
            'status'           => EmailEvent::STATUS_BOUNCED,
            'occurred_at'      => now()->subDays(400),
        ]);

        $this->artisan('postmaster:prune', ['--activity' => true])->assertSuccessful();

        // 60-day delivery: gone (past routine window).
        $this->assertDatabaseMissing('email_activity', ['id' => $oldDelivery->getKey()]);
        // 60-day bounce: kept (inside failure window).
        $this->assertDatabaseHas('email_activity', ['id' => $oldBounce->getKey()]);
        // 400-day bounce: gone (past failure window).
        $this->assertDatabaseMissing('email_activity', ['id' => $ancientBounce->getKey()]);
    }

    public function testPruneSkipsBucketsThatAreDisabled()
    {
        // Both events buckets off; content stays at default.
        config([
            'postmaster.persistence.prune_routine_activity_after_days' => 0,
            'postmaster.persistence.prune_failed_activity_after_days'  => 0,
            'postmaster.persistence.prune_content_after_days'        => 30,
        ]);

        $record = EmailMessage::create(['provider_message_id' => 'r']);
        $ancient = EmailActivity::create([
            'email_message_id' => $record->getKey(),
            'status'           => EmailEvent::STATUS_BOUNCED,
            'occurred_at'      => now()->subDays(1000),
        ]);

        $this->artisan('postmaster:prune')->assertSuccessful();

        // The ancient bounce stayed put because both event windows were off.
        $this->assertDatabaseHas('email_activity', ['id' => $ancient->getKey()]);
    }

    public function testAddressesAreTrackedByDefault()
    {
        Mail::raw('Hello', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertDatabaseCount('email_addresses', 1);
    }

    public function testAddressTrackingCanBeDisabled()
    {
        config(['postmaster.persistence.track_addresses' => false]);

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
        $this->assertSame(EmailEvent::STATUS_BOUNCED, $address->reason);
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
        $this->assertSame(EmailEvent::STATUS_COMPLAINED, $address->reason);
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
        $this->assertSame(EmailEvent::STATUS_DROPPED, $address->reason);
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

    public function testAutomaticSuppressionIsClearedByALaterDelivery()
    {
        // An automatic suppression (bounced/dropped/complained) is the
        // package's best guess that the address is bad. If a later
        // delivery webhook proves otherwise — operator resent after
        // confirming the address works, or the upstream issue resolved
        // itself — flip back to active. Same rule postmaster:sync follows
        // for the provider's side.
        config(['postmaster.persistence.track_addresses' => true]);

        event(EmailEvent::create(new Postmark([
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'MessageID'  => 'm-sticky-1',
            'Email'      => 'recovered@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
        ])));

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'm-sticky-2',
            'Recipient'   => 'recovered@example.com',
            'DeliveredAt' => '2021-02-01T00:00:00Z',
        ])));

        $address = EmailAddress::where('address', 'recovered@example.com')->first();
        $this->assertSame(EmailAddress::STATUS_ACTIVE, $address->status);
        $this->assertNull($address->reason);
        $this->assertNull($address->suppressed_at);

        // Activity entry written for the auto-clear. The default activity()
        // ordering is occurred_at ASC; reorder() to find the newest row.
        $latest = $address->activity()->reorder('id', 'desc')->first();
        $this->assertSame(EmailActivity::STATUS_UNSUPPRESSED, $latest->status);
    }

    public function testManualSuppressionIsNeverClearedByDelivery()
    {
        // Operator-asserted suppressions are intentional and not auto-
        // cleared by any webhook event. Only Postmaster::unsuppress()
        // lifts a manual one.
        config(['postmaster.persistence.track_addresses' => true]);

        Postmaster::suppress('manually-stuck@example.com');

        event(EmailEvent::create(new Postmark([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'm-manual-1',
            'Recipient'   => 'manually-stuck@example.com',
            'DeliveredAt' => '2021-02-01T00:00:00Z',
        ])));

        $address = EmailAddress::where('address', 'manually-stuck@example.com')->first();
        $this->assertSame(EmailAddress::STATUS_SUPPRESSED, $address->status);
        $this->assertSame(EmailAddress::REASON_MANUAL, $address->reason);
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
            ->expectsQuestion('Send the test email to which address?', 'tester@example.com')
            ->expectsOutputToContain('per-process')
            ->expectsOutputToContain('Test email sent to tester@example.com.');

        $this->assertDatabaseHas('email_messages', ['to_address' => 'tester@example.com']);
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
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $relayed[0]['status']);
    }
}
