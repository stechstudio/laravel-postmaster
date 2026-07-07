<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailMessage;

/**
 * The dashboard's "Release" action: send a previously sandboxed email for
 * real and flip its record to sent, once and only once.
 */
class SandboxReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.persistence.store_content', true);
        $app['config']->set('postmaster.persistence.record_events', true);
        $app['config']->set('postmaster.dashboard.enabled', true);
        $app['config']->set('postmaster.delivery', 'sandbox');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('mail.default', 'array');
    }

    /**
     * Send one email under sandbox mode and return its recorded row.
     */
    protected function sandboxOne(string $to = 'recipient@example.com'): EmailMessage
    {
        Mail::html('<p>Hello</p>', function ($m) use ($to) {
            $m->to($to)->subject('Greetings');
        });

        return EmailMessage::where('to_address', $to)->firstOrFail();
    }

    public function testSandboxSetupPrecondition()
    {
        $record = $this->sandboxOne();

        $this->assertTrue($record->isSandboxed());
        $this->assertStringStartsWith('sandboxed-', $record->provider_message_id);
        $this->assertSame('<p>Hello</p>', $record->html_body);
        // Nothing reached the transport under sandbox.
        $this->assertTrue(Mail::getSymfonyTransport()->messages()->isEmpty());
    }

    public function testReleaseSendsForRealAndLeavesSandbox()
    {
        $record = $this->sandboxOne();

        Postmaster::release($record);

        // The message actually went out this time — the release bypassed the
        // sandbox interceptor.
        $this->assertFalse(Mail::getSymfonyTransport()->messages()->isEmpty());

        $fresh = $record->fresh();
        $this->assertFalse($fresh->isSandboxed());
        // The synthetic sandbox id is replaced by the real provider id, so
        // webhooks now correlate to this row.
        $this->assertStringStartsNotWith('sandboxed-', (string) $fresh->provider_message_id);
        // Under the array test transport the sent status is "captured"; behind
        // a real provider (the usual sandbox setup) it is "sent".
        $this->assertSame(EmailEvent::STATUS_CAPTURED, $fresh->status);
    }

    public function testReleaseUpdatesTheExistingRowRatherThanCreatingANewOne()
    {
        $record = $this->sandboxOne();
        $this->assertDatabaseCount('email_messages', 1);

        Postmaster::release($record);

        $this->assertDatabaseCount('email_messages', 1);
    }

    public function testReleaseLogsATimelineEntry()
    {
        $record = $this->sandboxOne();

        Postmaster::release($record);

        $entry = $record->activity()->where('reason', 'released from sandbox')->first();
        $this->assertNotNull($entry);
        $this->assertSame(EmailEvent::STATUS_CAPTURED, $entry->status);
    }

    public function testAReleasedMessageCannotBeReleasedAgain()
    {
        $record = $this->sandboxOne();
        Postmaster::release($record);

        $this->expectException(\RuntimeException::class);
        Postmaster::release($record->fresh());
    }

    public function testReleaseRequiresStoredContent()
    {
        config(['postmaster.persistence.store_content' => false]);
        $record = $this->sandboxOne();
        $this->assertNull($record->html_body);

        $this->expectException(\RuntimeException::class);
        Postmaster::release($record);
    }

    public function testReleasingANeverSandboxedMessageThrows()
    {
        $record = EmailMessage::create([
            'provider_message_id' => 'real-1',
            'to_address'          => 'x@example.com',
            'status'              => EmailEvent::STATUS_SENT,
            'html_body'           => '<p>hi</p>',
        ]);

        $this->expectException(\RuntimeException::class);
        Postmaster::release($record);
    }

    public function testReleaseTransitionsEveryEnvelopeSibling()
    {
        Mail::html('<p>Hello all</p>', function ($m) {
            $m->to('to@example.com')->cc('cc@example.com')->subject('Team update');
        });

        $rows = EmailMessage::all();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->isSandboxed()));
        $this->assertCount(1, $rows->pluck('provider_message_id')->unique());

        Postmaster::release($rows->firstWhere('recipient_role', 'to'));

        $fresh = EmailMessage::all();
        $this->assertFalse($fresh->contains(fn ($r) => $r->isSandboxed()));
        // Both siblings now share the one real provider id from the send.
        $this->assertCount(1, $fresh->pluck('provider_message_id')->unique());
        $this->assertStringStartsNotWith('sandboxed-', (string) $fresh->first()->provider_message_id);
    }

    public function testDashboardShowsReleaseOnASandboxedMessage()
    {
        Postmaster::auth(fn () => true);
        $record = $this->sandboxOne();

        $this->get('/postmaster/messages/'.$record->getKey())
            ->assertOk()
            ->assertSee('Release')
            ->assertSee(route('postmaster.messages.release', $record), false);
    }

    public function testDashboardHidesReleaseOnASentMessage()
    {
        Postmaster::auth(fn () => true);
        $record = EmailMessage::create([
            'provider_message_id' => 'real-1',
            'to_address'          => 'x@example.com',
            'status'              => EmailEvent::STATUS_SENT,
            'html_body'           => '<p>hi</p>',
        ]);

        $this->get('/postmaster/messages/'.$record->getKey())
            ->assertOk()
            ->assertDontSee(route('postmaster.messages.release', $record), false);
    }

    public function testDashboardReleaseEndpointReleasesAndFlashes()
    {
        Postmaster::auth(fn () => true);
        $record = $this->sandboxOne();

        $this->post(route('postmaster.messages.release', $record))
            ->assertRedirect(route('postmaster.messages.show', $record))
            ->assertSessionHas('postmasterFlash');

        $this->assertFalse($record->fresh()->isSandboxed());
    }

    public function testResendIsHiddenWhileSandboxDeliveryIsActive()
    {
        Postmaster::auth(fn () => true);

        // A normal, genuinely-sent message — Resend would normally show, but
        // under sandbox mode a resend can't actually go out, so it's hidden.
        $record = EmailMessage::create([
            'provider_message_id' => 'real-1',
            'to_address'          => 'x@example.com',
            'status'              => EmailEvent::STATUS_DELIVERED,
            'html_body'           => '<p>hi</p>',
        ]);

        $this->get('/postmaster/messages/'.$record->getKey())
            ->assertOk()
            ->assertDontSee(route('postmaster.messages.resend', $record), false);
    }

    public function testAReleasedMessageShowsNeitherReleaseNorResend()
    {
        Postmaster::auth(fn () => true);
        $record = $this->sandboxOne();

        Postmaster::release($record);

        $this->get('/postmaster/messages/'.$record->getKey())
            ->assertOk()
            ->assertDontSee(route('postmaster.messages.release', $record), false)
            ->assertDontSee(route('postmaster.messages.resend', $record), false);
    }

    public function testDashboardReleaseEndpointRefusesANonSandboxedMessage()
    {
        Postmaster::auth(fn () => true);
        $record = EmailMessage::create([
            'provider_message_id' => 'real-1',
            'to_address'          => 'x@example.com',
            'status'              => EmailEvent::STATUS_SENT,
            'html_body'           => '<p>hi</p>',
        ]);

        $this->post(route('postmaster.messages.release', $record))
            ->assertRedirect(route('postmaster.messages.show', $record))
            ->assertSessionHas('postmasterError');
    }
}
