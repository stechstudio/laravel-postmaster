<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Tests\Stubs\Order;
use STS\Postmaster\Tests\Stubs\TrackedMail;

class SandboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.delivery', 'sandbox');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('mail.default', 'array');
    }

    public function testSandboxRecordsTheEmailWithoutSendingIt()
    {
        Mail::raw('Hello there', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        // Recorded...
        $this->assertDatabaseCount('email_messages', 1);

        $record = EmailMessage::first();
        $this->assertSame('recipient@example.com', $record->recipient);
        $this->assertSame('Greetings', $record->subject);
        $this->assertSame(EmailEvent::EVENT_SANDBOX, $record->status);

        // ...but never handed to the transport.
        $this->assertTrue(Mail::getSymfonyTransport()->messages()->isEmpty());
    }

    public function testSandboxMessageIdIsSynthetic()
    {
        Mail::raw('Hello there', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertStringStartsWith('sandbox-', EmailMessage::first()->message_id);
    }

    public function testSandboxCapturesRelatedModelAndTenant()
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        Mail::to('recipient@example.com')->send(new TrackedMail(related: $order, tenant: 7));

        $record = EmailMessage::first();
        $this->assertSame($order->getMorphClass(), $record->related_type);
        $this->assertSame((string) $order->getKey(), (string) $record->related_id);
        $this->assertSame('7', (string) $record->tenant_id);
        $this->assertSame(EmailEvent::EVENT_SANDBOX, $record->status);
    }

    public function testSandboxScopeReturnsSandboxedMessages()
    {
        Mail::raw('Hello there', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertSame(1, EmailMessage::sandbox()->count());
        $this->assertSame(0, EmailMessage::sent()->count());
    }

    public function testSandboxWithoutPersistenceSuppressesButRecordsNothing()
    {
        config()->set('postmaster.persistence.enabled', false);

        Mail::raw('Hello there', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertTrue(Mail::getSymfonyTransport()->messages()->isEmpty());
        $this->assertDatabaseCount('email_messages', 0);
    }
}
