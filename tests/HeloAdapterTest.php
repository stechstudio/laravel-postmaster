<?php

namespace STS\Postmaster\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Provider;
use STS\Postmaster\Providers\Helo\Adapter;

class HeloAdapterTest extends TestCase
{
    public static function events(): array
    {
        return [
            ['message-accepted', 'accepted'],
            ['email-delivered', 'delivered'], ['email-bounced', 'bounced'],
            ['email-opened', 'opened'], ['link-clicked', 'clicked'],
            ['recipient-complained', 'complained'], ['recipient-unsubscribed', 'unsubscribed'],
            ['recipient-resubscribed', 'resubscribed'],
        ];
    }

    public static function payload(string $event = 'email-delivered', array $extra = []): array
    {
        return array_replace([
            'eventType' => $event,
            'timestamp' => '2026-09-20T12:30:40.123456Z',
            'messageId' => '01930f2a-4b3c-7d8e-9f0a-1b2c3d4e5f6a',
            'channelId' => '550e8400-e29b-41d4-a716-446655440000',
            'mailType' => 'transactional',
            'recipient' => 'recipient@example.com',
            'tags' => ['receipt'],
            'metadata' => ['order_id' => '42'],
        ], $extra);
    }

    #[DataProvider('events')]
    public function testNormalizesEveryDeliveryEvent(string $type, string $status): void
    {
        $adapter = new Adapter(self::payload($type));
        $this->assertTrue($adapter->isValid());
        $this->assertSame('Helo', $adapter->provider());
        $this->assertSame($status, $adapter->status());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame('123456', $adapter->occurredAt()->format('u'));
        $this->assertSame(['receipt'], $adapter->tags()->all());
        $this->assertSame(['order_id' => '42'], $adapter->data()->all());
    }

    public function testExpandsAcceptedAndProcessedForEveryRecipient(): void
    {
        $payload = self::payload('message-accepted', ['recipients' => ['a@example.com', 'b@example.com', 'c@example.com']]);
        unset($payload['recipient']);
        $events = (new Provider('helo', Adapter::class, fn () => true))->adapt($payload)->getEvents();
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], array_map(fn ($event) => $event->toAddress(), $events));
    }

    public function testQuietlyIgnoresMessageProcessed(): void
    {
        // Postmaster's own outbound record is already "sent"; a second "sent"
        // from Helo only duplicated it in the timeline.
        $payload = self::payload('message-processed', ['recipients' => ['a@example.com']]);
        unset($payload['recipient']);
        \Illuminate\Support\Facades\Log::spy();
        $this->assertSame([], (new Provider('helo', Adapter::class, fn () => true))->adapt($payload)->getEvents());
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    }

    public function testExpandsARealAcceptedEventForEveryRecipient(): void
    {
        $payload = json_decode(file_get_contents(__DIR__.'/fixtures/helo/accepted.json'), true);
        $events = (new Provider('helo', Adapter::class, fn () => true))->adapt($payload)->getEvents();
        $this->assertSame(['recipient@example.com', 'cc@example.com', 'bcc@example.com'], array_map(fn ($event) => $event->toAddress(), $events));
        $this->assertSame(['accepted'], array_unique(array_map(fn ($event) => $event->status(), $events)));
    }

    public function testReadsTheRealBounceAndClickDetails(): void
    {
        $bounce = new Adapter(json_decode(file_get_contents(__DIR__.'/fixtures/helo/bounced.json'), true));
        $this->assertSame('5.1.1', $bounce->code());
        $this->assertStringContainsString('NoSuchUser', $bounce->response());
        $this->assertTrue($bounce->isPermanent());

        $click = new Adapter(json_decode(file_get_contents(__DIR__.'/fixtures/helo/clicked.json'), true));
        $this->assertSame('https://github.com/stechstudio/laravel-postmaster', $click->clickedUrl());
        $this->assertSame([], $click->tags()->all());
    }

    public function testKeepsBounceClassificationConservative(): void
    {
        // The live API sends Hard/Soft; the published schema says Permanent/Transient.
        foreach (['Hard' => 'hard', 'Permanent' => 'hard', 'Soft' => 'soft', 'Transient' => 'soft', 'Undetermined' => null] as $type => $expected) {
            $adapter = new Adapter(self::payload('email-bounced', ['details' => ['type' => $type, 'subType' => 'General', 'code' => 'smtp; 550 mailbox unavailable']]));
            $this->assertSame($expected, $adapter->bounceType());
            $this->assertSame($expected === 'hard', $adapter->isPermanent());
            $this->assertSame('General', $adapter->reason());
        }
        $this->assertNull((new Adapter(self::payload()))->bounceType());
    }

    public function testSplitsTheBounceDiagnosticIntoResponseAndStatusCode(): void
    {
        $diagnostic = "smtp; 550-5.1.1 The email account that you tried to reach does not exist. Please try\n550 5.1.1  https://support.google.com/mail/?p=NoSuchUser - gsmtp";
        $adapter = new Adapter(self::payload('email-bounced', ['details' => ['type' => 'Hard', 'code' => $diagnostic]]));
        $this->assertSame($diagnostic, $adapter->response());
        $this->assertSame('5.1.1', $adapter->code());

        $adapter = new Adapter(self::payload('email-bounced', ['details' => ['type' => 'Soft', 'code' => 'smtp; 452 mailbox full']]));
        $this->assertSame('452', $adapter->code());

        $this->assertNull((new Adapter(self::payload('email-bounced', ['details' => ['type' => 'Hard', 'code' => 'rejected']])))->code());
    }

    public function testExposesDeliveryResponseAndClickedLink(): void
    {
        $this->assertSame('250 OK', (new Adapter(self::payload(extra: ['details' => ['response' => '250 OK']])))->response());
        $this->assertSame('https://example.com/order', (new Adapter(self::payload('link-clicked', ['details' => ['link' => 'https://example.com/order']])))->clickedUrl());
    }

    public function testRejectsUnrelatedAndMalformedEventsWithoutTypeErrors(): void
    {
        foreach ([[], ['eventType' => 'domain-key-verified'], self::payload(extra: ['eventType' => []]), self::payload(extra: ['recipient' => []]), self::payload(extra: ['recipient' => '']), self::payload(extra: ['messageId' => null])] as $payload) {
            $this->assertNull(EmailEvent::create(new Adapter($payload)));
        }
        $this->assertNull((new Adapter(self::payload(extra: ['timestamp' => 'not a date'])))->occurredAt());
        $this->assertNull((new Adapter(self::payload(extra: ['timestamp' => null])))->occurredAt());
        $this->assertTrue(Adapter::supports(self::payload()));
        $this->assertFalse(Adapter::supports(['type' => 'email.delivered', 'data' => []]));
    }
}
