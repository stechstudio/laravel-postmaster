<?php

namespace STS\Postmaster\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use STS\Postmaster\Providers\Mailgun\Adapter as Mailgun;
use STS\Postmaster\Providers\Postmark\Adapter as Postmark;
use STS\Postmaster\Providers\Resend\Adapter as Resend;
use STS\Postmaster\Providers\SendGrid\Adapter as SendGrid;
use STS\Postmaster\Providers\Ses\Adapter as Ses;
use STS\Postmaster\EmailEvent;

/**
 * Exercises each adapter against captured webhook payloads stored as JSON
 * fixtures under tests/fixtures/{provider}/{event}.json.
 *
 * The fixtures committed here are representative samples. They should be
 * replaced with — or augmented by — real payloads captured from each
 * provider: field-name drift (see issue #2) only surfaces reliably when
 * tests run against genuine provider output rather than hand-built data.
 */
class FixtureTest extends TestCase
{
    public static function fixtures(): array
    {
        return [
            'sendgrid delivered' => [SendGrid::class, 'sendgrid/delivered.json', EmailEvent::EVENT_DELIVERED, null],
            'sendgrid bounce'    => [SendGrid::class, 'sendgrid/bounce.json', EmailEvent::EVENT_BOUNCED, EmailEvent::BOUNCE_HARD],
            'postmark delivery'  => [Postmark::class, 'postmark/delivery.json', EmailEvent::EVENT_DELIVERED, null],
            'postmark bounce'    => [Postmark::class, 'postmark/bounce.json', EmailEvent::EVENT_BOUNCED, EmailEvent::BOUNCE_HARD],
            'mailgun delivered'  => [Mailgun::class, 'mailgun/delivered.json', EmailEvent::EVENT_DELIVERED, null],
            'mailgun failed'     => [Mailgun::class, 'mailgun/failed.json', EmailEvent::EVENT_BOUNCED, EmailEvent::BOUNCE_HARD],
            'resend delivered'   => [Resend::class, 'resend/delivered.json', EmailEvent::EVENT_DELIVERED, null],
            'resend bounced'     => [Resend::class, 'resend/bounced.json', EmailEvent::EVENT_BOUNCED, EmailEvent::BOUNCE_HARD],
            'ses delivery'       => [Ses::class, 'ses/delivery.json', EmailEvent::EVENT_DELIVERED, null],
            'ses bounce'         => [Ses::class, 'ses/bounce.json', EmailEvent::EVENT_BOUNCED, EmailEvent::BOUNCE_HARD],
        ];
    }

    #[DataProvider('fixtures')]
    public function testFixtureProducesValidEvent($adapterClass, $fixture, $expectedAction, $expectedBounceType)
    {
        $payload = json_decode(file_get_contents(__DIR__ . '/fixtures/' . $fixture), true);

        $this->assertIsArray($payload, "$fixture is not valid JSON");

        $adapter = new $adapterClass($payload);

        $this->assertTrue($adapter->isValid(), "$fixture did not produce a valid event");
        $this->assertSame($expectedAction, $adapter->getAction());
        $this->assertIsString($adapter->getRecipient());
        $this->assertSame($expectedBounceType, $adapter->getBounceType());
        $this->assertInstanceOf(EmailEvent::class, EmailEvent::create($adapter));
    }
}
