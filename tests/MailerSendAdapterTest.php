<?php

namespace STS\Postmaster\Tests;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Provider;
use STS\Postmaster\Providers\MailerSend\Adapter;

/**
 * ping, sent, delivered, delivered-cc, and hard-bounced are redacted live
 * payloads. The other fixtures copy MailerSend's documented payloads.
 */
class MailerSendAdapterTest extends TestCase
{
    public static function events(): array
    {
        return [
            ['activity.sent', 'accepted'], ['activity.delivered', 'delivered'],
            ['activity.soft_bounced', 'bounced'], ['activity.hard_bounced', 'bounced'],
            ['activity.deferred', 'deferred'],
            ['activity.opened', 'opened'], ['activity.opened_unique', 'opened'],
            ['activity.clicked', 'clicked'], ['activity.clicked_unique', 'clicked'],
            ['activity.unsubscribed', 'unsubscribed'], ['activity.spam_complaint', 'complained'],
            ['activity.suppressed', 'dropped'],
        ];
    }

    public static function payload(string $type = 'activity.delivered', array $data = []): array
    {
        return [
            'type' => $type,
            'created_at' => '2025-08-05T21:23:56.123456Z',
            'data' => array_replace([
                'id' => '6892766a5b66e2daf3dc9156',
                'domain_id' => 'yv69oxl5kl785kw2',
                'message_id' => '6892766ae78995a317577aa1',
                'email_id' => '6892766a8d52ba62543d5e71',
                'type' => 'delivered',
                'subject' => 'Test email',
                'recipient' => 'recipient@example.com',
                'tags' => ['receipt'],
                'meta' => [],
            ], $data),
        ];
    }

    protected function fixture(string $name): Adapter
    {
        return new Adapter(json_decode(file_get_contents(__DIR__."/fixtures/mailersend/$name.json"), true));
    }

    #[DataProvider('events')]
    public function testNormalizesEveryDeliveryEvent(string $type, string $status): void
    {
        $adapter = new Adapter(self::payload($type));
        $this->assertTrue($adapter->isValid());
        $this->assertSame('MailerSend', $adapter->provider());
        $this->assertSame($status, $adapter->status());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame('6892766ae78995a317577aa1', $adapter->providerMessageId());
        $this->assertSame('2025-08-05 21:23:56.123456', $adapter->occurredAt()->format('Y-m-d H:i:s.u'));
        $this->assertSame(['receipt'], $adapter->tags()->all());
    }

    public function testReadsLivePayloadsForEachRecipient(): void
    {
        $sent = $this->fixture('sent');
        $this->assertSame(EmailEvent::STATUS_ACCEPTED, $sent->status());
        $this->assertSame('recipient@example.com', $sent->toAddress());

        // A Cc recipient gets its own events under the same message id.
        $to = $this->fixture('delivered');
        $cc = $this->fixture('delivered-cc');
        $this->assertSame([EmailEvent::STATUS_DELIVERED, 'cc@example.com'], [$cc->status(), $cc->toAddress()]);
        $this->assertSame($to->providerMessageId(), $cc->providerMessageId());
        $this->assertSame('2026-10-03 22:33:03', $to->occurredAt()->format('Y-m-d H:i:s'));
    }

    public function testReadsTheRecipientFromEitherV2Key(): void
    {
        // The current docs send data.recipient; the setup guide sends data.email.
        $payload = self::payload();
        $payload['data']['email'] = $payload['data']['recipient'];
        unset($payload['data']['recipient']);

        $this->assertSame('recipient@example.com', (new Adapter($payload))->toAddress());
    }

    public function testReadsBounceDetails(): void
    {
        $hard = $this->fixture('hard-bounced');
        $this->assertSame(EmailEvent::BOUNCE_HARD, $hard->bounceType());
        $this->assertTrue($hard->isPermanent());
        $this->assertStringStartsWith('The email account that you tried to reach does not exist', $hard->reason());
        $this->assertSame($hard->reason(), $hard->response());
        // A MailerSend code, not an SMTP reply code.
        $this->assertSame('34', $hard->code());
        $this->assertSame([], $hard->tags()->all());

        $soft = $this->fixture('soft-bounced');
        $this->assertSame(EmailEvent::BOUNCE_SOFT, $soft->bounceType());
        $this->assertFalse($soft->isPermanent());
        $this->assertSame('452', $soft->code());
    }

    public function testFallsBackToTheEventTypeForBounceSeverity(): void
    {
        $this->assertSame(EmailEvent::BOUNCE_HARD, (new Adapter(self::payload('activity.hard_bounced')))->bounceType());
        $this->assertSame(EmailEvent::BOUNCE_SOFT, (new Adapter(self::payload('activity.soft_bounced')))->bounceType());
        $this->assertNull((new Adapter(self::payload()))->bounceType());
        $this->assertNull((new Adapter(self::payload()))->code());
    }

    public function testReadsTheClickedUrlAndOtherReasons(): void
    {
        $this->assertSame('https://www.mailersend.com', $this->fixture('clicked')->clickedUrl());
        $this->assertNull($this->fixture('opened')->clickedUrl());
        $this->assertSame('NO_LONGER_WANT', $this->fixture('unsubscribed')->reason());
        $this->assertSame('on_hold', $this->fixture('suppressed')->reason());
    }

    public function testReadsLegacyV1Payloads(): void
    {
        $adapter = $this->fixture('v1-hard-bounced');
        $this->assertTrue($adapter->isValid());
        $this->assertSame(EmailEvent::STATUS_BOUNCED, $adapter->status());
        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->bounceType());
        $this->assertSame('test@example.com', $adapter->toAddress());
        $this->assertSame('62fb66bef54a112e920b5493', $adapter->providerMessageId());
        $this->assertSame('Host or domain name not found', $adapter->reason());
        $this->assertSame(['test-tag'], $adapter->tags()->all());
    }

    public function testQuietlyIgnoresThePingAndNonDeliveryEvents(): void
    {
        Log::spy();
        $provider = fn (array $payload) => (new Provider('mailersend', Adapter::class, fn () => true))->adapt($payload)->getEvents();

        $this->assertSame([], $provider(['type' => 'webhook.test', 'message' => 'This is a ping test message', 'created_at' => '2026-03-27T07:24:20.577080Z']));
        $this->assertSame([], $provider(['type' => 'maintenance.start', 'created_at' => '2025-08-05 22:27:14', 'data' => ['domain_id' => 'x']]));
        $this->assertSame([], $provider(['type' => 'recipient.on_hold_added', 'created_at' => '2026-03-18T16:38:48.607580Z', 'data' => ['email' => 'a@example.com']]));
        $this->assertSame([], $provider(self::payload('activity.survey_opened')));
        Log::shouldNotHaveReceived('warning');
    }

    public function testRejectsMalformedEventsWithoutTypeErrors(): void
    {
        foreach ([
            [], ['type' => 'activity.delivered'], ['type' => 'activity.delivered', 'data' => 'nope'],
            self::payload(data: ['recipient' => []]), self::payload(data: ['recipient' => '']),
            self::payload(data: ['message_id' => null]), array_replace(self::payload(), ['type' => []]),
        ] as $payload) {
            $this->assertNull(EmailEvent::create(new Adapter($payload)));
        }

        $adapter = new Adapter(self::payload('activity.hard_bounced', ['meta' => null, 'tags' => null]));
        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->bounceType());
        $this->assertSame([], $adapter->tags()->all());
        $this->assertNull((new Adapter(['created_at' => 'not a date'] + self::payload()))->occurredAt());
    }

    public function testParsesTheShortTimestampFormat(): void
    {
        $payload = ['created_at' => '2025-08-05 22:27:14'] + self::payload();
        $this->assertSame('2025-08-05 22:27:14', (new Adapter($payload))->occurredAt()->format('Y-m-d H:i:s'));
    }

    public function testSupportsOnlyMailerSendPayloads(): void
    {
        $this->assertTrue(Adapter::supports(self::payload()));
        $this->assertTrue(Adapter::supports(json_decode(file_get_contents(__DIR__.'/fixtures/mailersend/v1-hard-bounced.json'), true)));
        $this->assertFalse(Adapter::supports(['type' => 'email.delivered', 'data' => ['email_id' => 'x']]));
        $this->assertFalse(Adapter::supports(['eventType' => 'email-delivered']));
    }
}
