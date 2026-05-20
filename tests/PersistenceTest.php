<?php

namespace STS\EmailEvents\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use STS\EmailEvents\EmailEvent;
use STS\EmailEvents\Models\EmailMessage;
use STS\EmailEvents\Providers\Postmark\Adapter as Postmark;
use STS\EmailEvents\Tests\Stubs\Order;
use STS\EmailEvents\Tests\Stubs\OrderConfirmationMail;

class PersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        $app['config']->set('email-events.persistence.enabled', true);
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

    public function testRelatedHeadersAreStrippedBeforeSending()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        Mail::to('recipient@example.com')->send(new OrderConfirmationMail($order));

        $messages = Mail::getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $headers = $messages->first()->getOriginalMessage()->getHeaders();
        $this->assertFalse($headers->has('X-Email-Events-Related-Type'));
        $this->assertFalse($headers->has('X-Email-Events-Related-Id'));
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
