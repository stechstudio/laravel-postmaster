<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Providers\Postmark\Adapter as Postmark;
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
}
