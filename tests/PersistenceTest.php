<?php

namespace STS\EmailEvents\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use STS\EmailEvents\EmailEvent;
use STS\EmailEvents\Models\EmailMessage;
use STS\EmailEvents\Providers\Postmark\Adapter as Postmark;

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
