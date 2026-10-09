<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Transport\ArrayTransport;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailMessage;

/**
 * A provider that holds an address on its own suppression list accepts a
 * multi-recipient send and quietly skips that address. No webhook follows,
 * so the recorder has to mark the row itself or it reads "sent" forever.
 */
class SuppressedRecipientTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('mail.default', 'postmark');
        $app['config']->set('mail.mailers.postmark', ['transport' => 'postmark']);
        $app['config']->set('mail.mailers.resend', ['transport' => 'resend']);
    }

    /** The blocking listener registers at boot, so this has to be set before it. */
    protected function blockSuppressed($app): void
    {
        $app['config']->set('postmaster.block_suppressed', true);
    }

    protected ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand in for the real transports; detection reads only the name.
        $this->transport = new ArrayTransport;
        Mail::extend('postmark', fn () => $this->transport);
        Mail::extend('resend', fn () => new ArrayTransport);
    }

    protected function suppressAt(string $address, string $provider): void
    {
        $record = Postmaster::suppress($address, 'bounced');
        $record->recordProvider($provider);
        $record->save();
    }

    protected function send(?string $mailer = null): void
    {
        Mail::mailer($mailer)->raw('Invoice', function ($mail) {
            $mail->to(['billing@example.com', 'gone@example.com'])->subject('Invoice');
            $mail->getHeaders()->addTextHeader('X-Postmaster-Tenant', '7');
        });
    }

    public function testARecipientSuppressedAtTheSendingProviderIsRecordedAsDropped(): void
    {
        $this->suppressAt('gone@example.com', 'Postmark');

        $this->send();

        $this->assertSame(EmailEvent::STATUS_SENT, EmailMessage::where('to_address', 'billing@example.com')->sole()->status);

        $dropped = EmailMessage::where('to_address', 'gone@example.com')->sole();
        $this->assertSame(EmailEvent::STATUS_DROPPED, $dropped->status);
        $this->assertSame('Postmark', $dropped->provider);

        $activity = $dropped->activity()->sole();
        $this->assertSame(EmailEvent::STATUS_DROPPED, $activity->status);
        $this->assertSame('Postmark', $activity->provider);
        $this->assertSame('On the Postmark suppression list', $activity->reason);
    }

    public function testARecipientSuppressedOnlyAtAnotherProviderStaysSent(): void
    {
        $this->suppressAt('gone@example.com', 'Postmark');

        $this->send('resend');

        $this->assertSame(EmailEvent::STATUS_SENT, EmailMessage::where('to_address', 'gone@example.com')->sole()->status);
    }

    public function testAManualSuppressionStaysSent(): void
    {
        // The provider never heard of a suppression made only in Postmaster.
        Postmaster::suppress('gone@example.com');

        $this->send();

        $this->assertSame(EmailEvent::STATUS_SENT, EmailMessage::where('to_address', 'gone@example.com')->sole()->status);
    }

    #[DefineEnvironment('blockSuppressed')]
    public function testBlockingRemovesOnlyTheSuppressedRecipients(): void
    {
        Postmaster::suppress('gone@example.com');

        $this->send();

        $recipients = $this->transport->messages()->sole()->getEnvelope()->getRecipients();
        $this->assertSame(['billing@example.com'], array_map(fn ($a) => $a->getAddress(), $recipients));
        $this->assertSame(EmailEvent::STATUS_SENT, EmailMessage::where('to_address', 'billing@example.com')->sole()->status);
        $blocked = EmailMessage::where('to_address', 'gone@example.com')->sole();
        $this->assertSame(EmailEvent::STATUS_BLOCKED, $blocked->status);
        $this->assertStringStartsWith('blocked-', $blocked->provider_message_id);

        // Both halves keep the metadata the message carried.
        $this->assertSame(2, EmailMessage::forTenant(7)->count());
    }

    #[DefineEnvironment('blockSuppressed')]
    public function testBlockingCancelsTheSendWhenEveryRecipientIsSuppressed(): void
    {
        Postmaster::suppress('billing@example.com');
        Postmaster::suppress('gone@example.com');

        $this->send();

        $this->assertCount(0, $this->transport->messages());
        $this->assertSame(2, EmailMessage::where('status', EmailEvent::STATUS_BLOCKED)->count());
        $this->assertSame(0, EmailMessage::where('status', EmailEvent::STATUS_SENT)->count());
    }
}
