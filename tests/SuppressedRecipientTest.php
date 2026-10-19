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
        $app['config']->set('mail.mailers.postshiba', ['transport' => 'postshiba']);
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
        Mail::extend('postshiba', fn () => $this->transport);
    }

    protected function suppressAt(string $address, string $provider): void
    {
        $record = Postmaster::suppress($address, 'bounced');
        $record->recordProvider($provider);
        $record->save();
    }

    protected function send(?string $mailer = null, array $to = ['billing@example.com', 'gone@example.com']): void
    {
        Mail::mailer($mailer)->raw('Invoice', function ($mail) use ($to) {
            $mail->to($to)->subject('Invoice');
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

    public function testPostShibaDropsATaggedAliasOfASuppressedAddress(): void
    {
        // PostShiba checks its list without the "+tag", so it skips gone+news@.
        $this->suppressAt('gone@example.com', 'PostShiba');

        $this->send('postshiba', ['billing@example.com', 'gone+news@example.com']);

        $this->assertSame(EmailEvent::STATUS_SENT, EmailMessage::where('to_address', 'billing@example.com')->sole()->status);

        $dropped = EmailMessage::where('to_address', 'gone+news@example.com')->sole();
        $this->assertSame(EmailEvent::STATUS_DROPPED, $dropped->status);
        $this->assertSame('On the PostShiba suppression list as gone@example.com', $dropped->activity()->sole()->reason);
    }

    public function testOtherProvidersSendToATaggedAlias(): void
    {
        $this->suppressAt('gone@example.com', 'Postmark');

        $this->send(to: ['billing@example.com', 'gone+news@example.com']);

        $this->assertSame(EmailEvent::STATUS_SENT, EmailMessage::where('to_address', 'gone+news@example.com')->sole()->status);
    }

    #[DefineEnvironment('blockSuppressed')]
    public function testBlockingRemovesATaggedAliasPostShibaWouldSkip(): void
    {
        $this->suppressAt('gone@example.com', 'PostShiba');

        $this->send('postshiba', ['billing@example.com', 'gone+news@example.com']);

        $recipients = $this->transport->messages()->sole()->getEnvelope()->getRecipients();
        $this->assertSame(['billing@example.com'], array_map(fn ($a) => $a->getAddress(), $recipients));
        $this->assertSame(EmailEvent::STATUS_BLOCKED, EmailMessage::where('to_address', 'gone+news@example.com')->sole()->status);
    }

    #[DefineEnvironment('blockSuppressed')]
    public function testBlockingCancelsASendPostShibaWouldRefuse(): void
    {
        // PostShiba answers 403 "suppressed" when it would skip every recipient.
        $this->suppressAt('gone@example.com', 'PostShiba');

        $this->send('postshiba', ['gone+news@example.com']);

        $this->assertCount(0, $this->transport->messages());
        $this->assertSame(EmailEvent::STATUS_BLOCKED, EmailMessage::where('to_address', 'gone+news@example.com')->sole()->status);
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
