<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailMessage;

class SuppressionBlockingTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.block_suppressed', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('mail.default', 'array');
    }

    public function testSuppressedRecipientIsBlockedAndRecorded()
    {
        Postmaster::suppress('alice@example.com');

        Mail::raw('Hi', function ($message) {
            $message->to('alice@example.com')->subject('Welcome');
        });

        // The message never reached the transport.
        $this->assertCount(0, Mail::getSymfonyTransport()->messages());

        // …but the attempt is on record, flagged as blocked.
        $record = EmailMessage::first();
        $this->assertNotNull($record);
        $this->assertSame('alice@example.com', $record->to_address);
        $this->assertTrue($record->isBlocked());
        $this->assertStringStartsWith('blocked-', $record->provider_message_id);
    }

    public function testUnsuppressedRecipientGoesThroughNormally()
    {
        // Alice is suppressed; Bob is not.
        Postmaster::suppress('alice@example.com');

        Mail::raw('Hi Bob', function ($message) {
            $message->to('bob@example.com')->subject('Hello');
        });

        $this->assertCount(1, Mail::getSymfonyTransport()->messages());
        $this->assertTrue(EmailMessage::first()->isCaptured());
    }

    public function testFeatureIsOffByDefault()
    {
        config()->set('postmaster.block_suppressed', false);
        Postmaster::suppress('alice@example.com');

        Mail::raw('Hi', function ($message) {
            $message->to('alice@example.com')->subject('Welcome');
        });

        // Default behaviour: the package never silently drops mail. The
        // app has to opt in to block-suppressed.
        $this->assertCount(1, Mail::getSymfonyTransport()->messages());
    }
}
