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
        $this->assertSame('recipient@example.com', $record->to_address);
        $this->assertSame('Greetings', $record->subject);
        $this->assertSame(EmailEvent::STATUS_SANDBOXED, $record->status);

        // ...but never handed to the transport.
        $this->assertTrue(Mail::getSymfonyTransport()->messages()->isEmpty());
    }

    public function testSandboxMessageIdIsSynthetic()
    {
        Mail::raw('Hello there', function ($message) {
            $message->to('recipient@example.com')->subject('Greetings');
        });

        $this->assertStringStartsWith('sandboxed-', EmailMessage::first()->provider_message_id);
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
        $this->assertSame(EmailEvent::STATUS_SANDBOXED, $record->status);
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

    public function testVerifyStillRunsTheRoundTripUnderSandbox()
    {
        config(['cache.default' => 'array']);

        // Verify warns that sandbox is on, then sends its one test email
        // anyway — bypassing the interceptor — rather than refusing.
        $this->artisan('postmaster:verify')
            ->expectsOutputToContain('Sandbox delivery is enabled')
            ->expectsChoice('Which provider are you verifying?', 'postmark', ['sendgrid', 'postmark', 'mailgun', 'ses', 'resend'])
            ->expectsConfirmation('Have you set that webhook URL in your postmark dashboard?', 'yes')
            ->expectsQuestion('Send the test email to which address?', 'tester@example.com')
            ->expectsOutputToContain('Test email sent to tester@example.com.')
            ->assertExitCode(0);

        // The test email reached the transport and was recorded as a real
        // send, not intercepted and suppressed as sandboxed.
        $this->assertFalse(Mail::getSymfonyTransport()->messages()->isEmpty());
        $this->assertDatabaseHas('email_messages', ['to_address' => 'tester@example.com']);
        $this->assertSame(0, EmailMessage::sandbox()->where('to_address', 'tester@example.com')->count());
    }

    public function testVerifyLeavesTheSandboxSettingUnchanged()
    {
        config(['cache.default' => 'array']);

        $this->artisan('postmaster:verify')
            ->expectsChoice('Which provider are you verifying?', 'postmark', ['sendgrid', 'postmark', 'mailgun', 'ses', 'resend'])
            ->expectsConfirmation('Have you set that webhook URL in your postmark dashboard?', 'yes')
            ->expectsQuestion('Send the test email to which address?', 'tester@example.com');

        // The bypass is scoped to the one send; sandbox mode is still on after.
        $this->assertSame('sandbox', config('postmaster.delivery'));

        // A subsequent normal send is intercepted again, proving the toggle
        // was restored.
        Mail::raw('Hello', function ($message) {
            $message->to('after@example.com')->subject('After');
        });

        $this->assertSame(1, EmailMessage::sandbox()->where('to_address', 'after@example.com')->count());
    }
}
