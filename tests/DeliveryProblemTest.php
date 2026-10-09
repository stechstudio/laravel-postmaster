<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;

class DeliveryProblemTest extends TestCase
{
    use RefreshDatabase;

    /** What Postmark sent for a real Exchange Online rejection. */
    protected const string OUTLOOK_REJECTION = 'smtp; 550 5.4.1 Recipient address rejected: Access denied. '
        .'For more information see https://aka.ms/EXOSmtpErrors '
        .'[SA2PEPF00003AE6.namprd02.prod.outlook.com 2026-10-08T20:36:23.875Z 08DF254F56560914]';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.dashboard.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function bounce(): EmailActivity
    {
        return new EmailActivity([
            'status'      => EmailEvent::STATUS_BOUNCED,
            'bounce_type' => EmailEvent::BOUNCE_HARD,
            'provider'    => 'Postmark',
            'response'    => self::OUTLOOK_REJECTION,
            'reason'      => 'HardBounce',
            'code'        => '1',
            'occurred_at' => '2026-10-08 20:36:24',
        ]);
    }

    public function testTheSmtpStatusComesFromTheResponseNotPostmarksTypeCode(): void
    {
        $this->assertSame('5.4.1', $this->bounce()->smtpStatus());
        $this->assertNull(new EmailActivity(['code' => '1'])->smtpStatus());
        $this->assertSame('5.1.1', new EmailActivity(['code' => '5.1.1'])->smtpStatus());
    }

    public function testEachProblemStatusHasASummary(): void
    {
        $summary = fn (array $attributes) => new EmailActivity($attributes)->summary();

        $this->assertSame('The receiving mail server permanently rejected this email.', $this->bounce()->summary());
        $this->assertSame('The receiving mail server temporarily rejected this email.', $summary(['status' => EmailEvent::STATUS_BOUNCED, 'bounce_type' => EmailEvent::BOUNCE_SOFT]));
        $this->assertSame('Postmark did not send this email.', $summary(['status' => EmailEvent::STATUS_DROPPED, 'provider' => 'Postmark']));
        $this->assertSame('The recipient marked this email as spam.', $summary(['status' => EmailEvent::STATUS_COMPLAINED]));
        $this->assertSame(
            'Postmaster did not send this email because the address is on the suppression list.',
            $summary(['status' => EmailEvent::STATUS_BLOCKED]),
        );
        $this->assertNull($summary(['status' => EmailEvent::STATUS_DELIVERED]));
    }

    public function testTheMessagePageShowsTheDeliveryProblem(): void
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'jo@example.com', 'status' => EmailEvent::STATUS_BOUNCED]);
        $message->activity()->create(['status' => EmailEvent::STATUS_SENT, 'occurred_at' => '2026-10-08 20:36:20']);
        $message->activity()->save($this->bounce());

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertSee('data-problem-status="bounced"', false)
            ->assertSee('The receiving mail server permanently rejected this email.')
            ->assertSee('08DF254F56560914')
            ->assertSee('5.4.1')
            ->assertSee('HardBounce');
    }

    public function testTheTimelineShowsTheResponseOfAnEarlierDeferral(): void
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'jo@example.com', 'status' => EmailEvent::STATUS_BOUNCED]);
        $message->activity()->create(['status' => EmailEvent::STATUS_DEFERRED, 'response' => '451 4.7.1 Try again later', 'occurred_at' => '2026-10-08 20:30:00']);
        $message->activity()->save($this->bounce());

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertSee('451 4.7.1 Try again later')
            ->assertSee('08DF254F56560914');
    }

    /**
     * A message to $address with one activity entry, as the address ledger
     * would hold it.
     *
     * @param array<string, mixed> $event
     */
    protected function sendWith(EmailAddress $address, string $status, array $event): EmailActivity
    {
        $message = EmailMessage::create(['provider_message_id' => uniqid(), 'to_address' => $address->address, 'status' => $status]);

        return $message->activity()->create($event + ['status' => $status, 'email_address_id' => $address->getKey()]);
    }

    protected function suppressionDrop(EmailAddress $address, string $at = '2026-10-08 20:42:59'): EmailActivity
    {
        return $this->sendWith($address, EmailEvent::STATUS_DROPPED, [
            'provider' => 'Postmark', 'reason' => 'On the Postmark suppression list', 'occurred_at' => $at,
        ]);
    }

    public function testADropTracesBackToTheBounceThatSuppressedTheAddress(): void
    {
        $address = EmailAddress::create(['address' => 'jo@example.com']);
        $bounce = $this->sendWith($address, EmailEvent::STATUS_BOUNCED, $this->bounce()->getAttributes());

        $this->assertTrue($this->suppressionDrop($address)->suppressionCause()->is($bounce));
    }

    public function testAComplaintOrABlockedSendTracesBackToo(): void
    {
        $address = EmailAddress::create(['address' => 'jo@example.com']);
        $complaint = $this->sendWith($address, EmailEvent::STATUS_COMPLAINED, ['occurred_at' => '2026-10-01 09:00:00']);
        $blocked = $this->sendWith($address, EmailEvent::STATUS_BLOCKED, ['occurred_at' => '2026-10-02 09:00:00']);

        $this->assertTrue($blocked->suppressionCause()->is($complaint));
    }

    public function testOnlyAnEarlierPermanentFailureSinceTheLastUnsuppressCounts(): void
    {
        $address = EmailAddress::create(['address' => 'jo@example.com']);
        $other = EmailAddress::create(['address' => 'someone@example.com']);
        $this->sendWith($address, EmailEvent::STATUS_BOUNCED, ['bounce_type' => EmailEvent::BOUNCE_HARD, 'occurred_at' => '2026-09-01 09:00:00']);
        $address->logActivity(['status' => EmailActivity::STATUS_UNSUPPRESSED, 'occurred_at' => '2026-09-02 09:00:00']);
        $this->sendWith($address, EmailEvent::STATUS_BOUNCED, ['bounce_type' => EmailEvent::BOUNCE_SOFT, 'occurred_at' => '2026-10-07 09:00:00']);
        $this->sendWith($other, EmailEvent::STATUS_BOUNCED, ['bounce_type' => EmailEvent::BOUNCE_HARD, 'occurred_at' => '2026-10-08 09:00:00']);
        $drop = $this->suppressionDrop($address);
        $this->sendWith($address, EmailEvent::STATUS_BOUNCED, ['bounce_type' => EmailEvent::BOUNCE_HARD, 'occurred_at' => '2026-10-09 09:00:00']);

        $this->assertNull($drop->suppressionCause());
    }

    public function testOnlyADropOrABlockedSendHasASuppressionCause(): void
    {
        $address = EmailAddress::create(['address' => 'jo@example.com']);
        $this->sendWith($address, EmailEvent::STATUS_BOUNCED, $this->bounce()->getAttributes());
        $later = $this->sendWith($address, EmailEvent::STATUS_BOUNCED, ['bounce_type' => EmailEvent::BOUNCE_HARD, 'occurred_at' => '2026-10-09 09:00:00']);

        $this->assertNull($later->suppressionCause());
        $this->assertNull(new EmailActivity(['status' => EmailEvent::STATUS_DROPPED])->suppressionCause());
    }

    public function testTheMessagePageShowsTheBounceBehindADrop(): void
    {
        Postmaster::auth(fn () => true);
        $address = EmailAddress::create(['address' => 'jo@example.com']);
        $bounce = $this->sendWith($address, EmailEvent::STATUS_BOUNCED, $this->bounce()->getAttributes());
        $drop = $this->suppressionDrop($address);

        $this->get('/postmaster/messages/'.$drop->email_message_id)
            ->assertOk()
            ->assertSee('data-problem-status="dropped"', false)
            ->assertSee('data-problem-cause="bounced"', false)
            ->assertSee('An earlier email to this address bounced on')
            ->assertSee('/postmaster/messages/'.$bounce->email_message_id, false)
            ->assertSee('08DF254F56560914')
            ->assertSee('5.4.1');
    }

    public function testADeliveredMessageHasNoProblemCard(): void
    {
        Postmaster::auth(fn () => true);
        $message = EmailMessage::create(['provider_message_id' => 'm1', 'to_address' => 'jo@example.com', 'status' => EmailEvent::STATUS_DELIVERED]);
        $message->activity()->create(['status' => EmailEvent::STATUS_DELIVERED, 'response' => 'smtp;250 2.0.0 OK', 'occurred_at' => '2026-10-08 20:36:20']);

        $this->get('/postmaster/messages/'.$message->getKey())
            ->assertOk()
            ->assertDontSee('data-problem-status', false);
    }
}
