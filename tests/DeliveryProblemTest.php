<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;
use STS\Postmaster\Models\EmailActivity;
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

    public function testTheResponseTextDropsTheCodesAndServerTrace(): void
    {
        $this->assertSame(
            'Recipient address rejected: Access denied. For more information see https://aka.ms/EXOSmtpErrors',
            $this->bounce()->responseText(),
        );
        $this->assertSame('mailbox does not exist', new EmailActivity(['response' => 'smtp;550 5.1.1 mailbox does not exist'])->responseText());
    }

    public function testTheSummaryQuotesTheServer(): void
    {
        $this->assertSame(
            'On Oct 8, 2026 at 20:36 UTC, the receiving mail server permanently rejected the email to jo@example.com. '
            .'The server said: "Recipient address rejected: Access denied. For more information see https://aka.ms/EXOSmtpErrors" (SMTP status 5.4.1).',
            $this->bounce()->summary('jo@example.com'),
        );
        $this->assertSame(
            'On Oct 8, 2026 at 20:36 UTC, the receiving mail server permanently rejected the email to jo@example.com.',
            $this->bounce()->headline('jo@example.com'),
        );
    }

    public function testEachProblemStatusHasASummary(): void
    {
        $summary = fn (array $attributes) => new EmailActivity($attributes)->summary('jo@example.com');

        $this->assertSame(
            'Postmark did not send the email to jo@example.com. Reason: On the Postmark suppression list.',
            $summary(['status' => EmailEvent::STATUS_DROPPED, 'provider' => 'Postmark', 'reason' => 'On the Postmark suppression list']),
        );
        $this->assertSame(
            'The recipient jo@example.com marked the email as spam.',
            $summary(['status' => EmailEvent::STATUS_COMPLAINED]),
        );
        $this->assertSame(
            'Postmaster did not send the email to jo@example.com because the address is on the suppression list.',
            $summary(['status' => EmailEvent::STATUS_BLOCKED]),
        );
        $this->assertStringContainsString('temporarily rejected', $summary(['status' => EmailEvent::STATUS_BOUNCED, 'bounce_type' => EmailEvent::BOUNCE_SOFT]));
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
            ->assertSee('the receiving mail server permanently rejected the email to jo@example.com')
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
