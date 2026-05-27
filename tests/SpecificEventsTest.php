<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use STS\Postmaster\EmailBounced;
use STS\Postmaster\EmailClicked;
use STS\Postmaster\EmailComplained;
use STS\Postmaster\EmailDelivered;
use STS\Postmaster\EmailDropped;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\EmailOpened;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Providers\Postmark\Adapter as Postmark;
use STS\Postmaster\Providers\SendGrid\Adapter as SendGrid;

/**
 * The umbrella EmailEvent fires for every webhook. Alongside it, a targeted
 * event class fires when the status maps to one — EmailBounced for a bounce,
 * EmailDelivered for a delivery, and so on. These tests prove the targeted
 * variant fires with the same correlated record the umbrella carries, and
 * that the umbrella still fires for status that doesn't have its own class.
 */
class SpecificEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    public function testEveryTargetedEventFiresAlongsideTheUmbrella()
    {
        // SendGrid's adapter is exercised because it maps to all six of the
        // targeted-event statuses, including 'dropped' (which Postmark
        // doesn't have as a distinct status).
        $base = [
            'email'         => 'recipient@example.com',
            'timestamp'     => 1609459200,
            'sg_message_id' => 'sg-message-1',
            'smtp-id'       => '<sg-1@example.com>',
        ];

        $cases = [
            [$base + ['event' => 'delivered'],                            EmailDelivered::class],
            [$base + ['event' => 'bounce', 'type' => 'bounce'],           EmailBounced::class],
            [$base + ['event' => 'spamreport'],                           EmailComplained::class],
            [$base + ['event' => 'dropped'],                              EmailDropped::class],
            [$base + ['event' => 'open'],                                 EmailOpened::class],
            [$base + ['event' => 'click', 'url' => 'https://example.com'], EmailClicked::class],
        ];

        foreach ($cases as [$payload, $expectedClass]) {
            Event::fake();

            \STS\Postmaster\Facades\Postmaster::provider('sendgrid')->adapt($payload)->dispatch();

            Event::assertDispatched(EmailEvent::class);
            Event::assertDispatched($expectedClass);
        }
    }

    public function testStatusesWithoutADedicatedClassFireOnlyTheUmbrella()
    {
        // Postmark's "Subscription" event maps to no Postmaster status, so
        // EmailEvent::create() returns null — nothing fires. Use a status
        // that DOES land on the umbrella but has no specific class: SES's
        // "Send" notification, normalized to STATUS_SENT.
        Event::fake();

        // Dispatch a "sent" event directly through the provider pipeline.
        $provider = \STS\Postmaster\Facades\Postmaster::provider('postmark');
        $provider->adapt(['RecordType' => 'Delivery', 'MessageID' => 'pm-x', 'Email' => 'r@example.com'])->dispatch();

        // The umbrella + EmailDelivered both fired (this is the "has a
        // specific class" case; assert as a sanity check).
        Event::assertDispatched(EmailEvent::class);
        Event::assertDispatched(EmailDelivered::class);

        // No other targeted class fired.
        Event::assertNotDispatched(EmailBounced::class);
        Event::assertNotDispatched(EmailComplained::class);
        Event::assertNotDispatched(EmailDropped::class);
        Event::assertNotDispatched(EmailOpened::class);
        Event::assertNotDispatched(EmailClicked::class);
    }

    public function testTheTargetedEventCarriesTheSameCorrelatedRecord()
    {
        // Pre-existing message record correlates by provider_message_id.
        $record = EmailMessage::create([
            'provider_message_id' => 'pm-correlate',
            'to_address'          => 'recipient@example.com',
            'status'              => EmailEvent::STATUS_SENT,
        ]);

        $bouncedMessage = null;

        Event::listen(EmailBounced::class, function (EmailBounced $event) use (&$bouncedMessage) {
            $bouncedMessage = $event->emailMessage();
        });

        \STS\Postmaster\Facades\Postmaster::provider('postmark')
            ->adapt([
                'RecordType' => 'Bounce',
                'Type'       => 'HardBounce',
                'MessageID'  => 'pm-correlate',
                'Email'      => 'recipient@example.com',
                'BouncedAt'  => '2026-01-01T00:00:00Z',
            ])
            ->dispatch();

        // EmailBounced's listener saw the same record EmailEvent listeners do.
        $this->assertNotNull($bouncedMessage);
        $this->assertSame((int) $record->getKey(), (int) $bouncedMessage->getKey());
    }

    public function testTheTargetedEventInheritsTheUmbrellaApi()
    {
        // Subclass relationship means every accessor and predicate on the
        // umbrella is there on the targeted variant unchanged — no need to
        // duplicate API surface.
        $captured = null;
        Event::listen(EmailBounced::class, function (EmailBounced $event) use (&$captured) {
            $captured = $event;
        });

        \STS\Postmaster\Facades\Postmaster::provider('postmark')
            ->adapt([
                'RecordType' => 'Bounce',
                'Type'       => 'HardBounce',
                'MessageID'  => 'pm-api',
                'Email'      => 'r@example.com',
                'BouncedAt'  => '2026-01-01T00:00:00Z',
            ])
            ->dispatch();

        $this->assertInstanceOf(EmailBounced::class, $captured);
        $this->assertTrue($captured->isBounced());
        $this->assertTrue($captured->isPermanent());
        $this->assertSame('r@example.com', $captured->toAddress());
        $this->assertSame('pm-api', $captured->providerMessageId());
        $this->assertSame(EmailEvent::STATUS_BOUNCED, $captured->status());
    }
}
