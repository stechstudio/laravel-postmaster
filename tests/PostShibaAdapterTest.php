<?php

namespace STS\Postmaster\Tests;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Provider;
use STS\Postmaster\Providers\PostShiba\Adapter;

/**
 * Fixtures follow PostShiba's documented event fields. sg_message_id differs
 * from smtp-id on purpose, as it does on real sends.
 */
class PostShibaAdapterTest extends TestCase
{
    public static function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__."/fixtures/postshiba/$name.json"), true);
    }

    public static function events(): array
    {
        return [
            'processed'   => ['processed', EmailEvent::STATUS_ACCEPTED, null],
            'delivered'   => ['delivered', EmailEvent::STATUS_DELIVERED, null],
            'deferred'    => ['deferred', EmailEvent::STATUS_DEFERRED, null],
            'hard bounce' => ['bounce', EmailEvent::STATUS_BOUNCED, EmailEvent::BOUNCE_HARD],
            'blocked'     => ['blocked', EmailEvent::STATUS_BOUNCED, EmailEvent::BOUNCE_BLOCK],
            'dropped'     => ['dropped', EmailEvent::STATUS_DROPPED, EmailEvent::BOUNCE_HARD],
            'spamreport'  => ['spamreport', EmailEvent::STATUS_COMPLAINED, null],
            'unsubscribe' => ['unsubscribe', EmailEvent::STATUS_UNSUBSCRIBED, null],
        ];
    }

    #[DataProvider('events')]
    public function testNormalizesEveryEvent(string $fixture, string $status, ?string $bounceType): void
    {
        $adapter = new Adapter(self::fixture($fixture));

        $this->assertTrue($adapter->isValid());
        $this->assertSame('PostShiba', $adapter->provider());
        $this->assertSame($status, $adapter->status());
        $this->assertSame($bounceType, $adapter->bounceType());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame('2026-09-21 14:13:20', $adapter->occurredAt()->format('Y-m-d H:i:s'));
    }

    public function testMatchesOnSmtpIdWithoutAngleBrackets(): void
    {
        $this->assertSame('abc@capsule.test', (new Adapter(self::fixture('delivered')))->providerMessageId());
    }

    public function testIsInvalidWithoutSmtpId(): void
    {
        $payload = self::fixture('delivered');
        unset($payload['smtp-id']);

        $this->assertFalse((new Adapter($payload))->isValid());
        $this->assertFalse((new Adapter(['smtp-id' => '<>'] + $payload))->isValid());
    }

    public function testReadsTheFailureDetails(): void
    {
        $adapter = new Adapter(self::fixture('bounce'));

        // The code is only in "status"; the response text has no "550 5.1.1".
        $this->assertSame('5.1.1', $adapter->code());
        $this->assertStringStartsWith('The email account that you tried to reach does not exist.', $adapter->reason());
        $this->assertStringStartsWith('The email account that you tried to reach does not exist.', $adapter->response());
        $this->assertTrue($adapter->isPermanent());

        $blocked = new Adapter(self::fixture('blocked'));
        $this->assertSame('5.1.10', $blocked->code());
        $this->assertSame('Recipient address has a null MX', $blocked->reason());
    }

    public function testReturnsTheUniqueArgsAsData(): void
    {
        $this->assertSame(['campaign_id' => 'cmp_123', 'site' => 'docs'], (new Adapter(self::fixture('delivered')))->data()->all());
    }

    public function testRecognizesPostShibaPayloads(): void
    {
        $this->assertTrue(Adapter::supports(self::fixture('delivered')));
        $this->assertFalse(Adapter::supports(json_decode(file_get_contents(__DIR__.'/fixtures/sendgrid/delivered.json'), true)));
    }

    public function testDropsTestEventsWithoutLogging(): void
    {
        Log::spy();

        $provider = (new Provider('postshiba', Adapter::class, fn () => true))->adapt([self::fixture('test')]);

        $this->assertSame([], $provider->getEvents());
        Log::shouldNotHaveReceived('warning');
    }

    public function testAdaptsEveryEventInTheBody(): void
    {
        $provider = (new Provider('postshiba', Adapter::class, fn () => true))
            ->adapt([self::fixture('processed'), self::fixture('delivered')]);

        $this->assertSame(['accepted', 'delivered'], array_map(fn (EmailEvent $event) => $event->adapter()->status(), $provider->getEvents()));
    }
}
