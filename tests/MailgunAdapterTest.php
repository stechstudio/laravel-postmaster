<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\Providers\Mailgun\Adapter as Mailgun;
use STS\Postmaster\EmailEvent;

class MailgunAdapterTest extends TestCase
{
    protected function deliveredPayload()
    {
        return [
            'signature' => [
                'token'     => 'signature-token',
                'timestamp' => '1609459200',
                'signature' => 'signature-hash',
            ],
            'event-data' => [
                'event'           => 'delivered',
                // `id` is Mailgun's per-event token; correlation runs
                // against the email's Message-ID header instead.
                'id'              => 'Nz5rUz2sT6OY5t7hJt2WsA',
                'recipient'       => 'recipient@example.com',
                'timestamp'       => 1609459200,
                'tags'            => ['welcome', 'newsletter'],
                'user-variables'  => ['order_id' => '1234'],
                'message'         => [
                    'headers' => [
                        'message-id' => 'mailgun-message-1@example.com',
                    ],
                ],
                'delivery-status' => [
                    'code'        => 250,
                    'description' => 'OK',
                    'message'     => 'queued as ABC',
                ],
            ],
        ];
    }

    public function testSupports()
    {
        $this->assertTrue(Mailgun::supports($this->deliveredPayload()));
        $this->assertFalse(Mailgun::supports(['signature' => []]));
        $this->assertFalse(Mailgun::supports(['event-data' => []]));
    }

    public function testParsesDeliveredEvent()
    {
        $adapter = new Mailgun($this->deliveredPayload());

        $this->assertTrue($adapter->isValid());
        $this->assertSame('Mailgun', $adapter->provider());
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $adapter->status());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame(1609459200, $adapter->occurredAt()->getTimestamp());
        $this->assertSame('mailgun-message-1@example.com', $adapter->providerMessageId());
        $this->assertSame(['welcome', 'newsletter'], $adapter->tags()->all());
        $this->assertSame(['order_id' => '1234'], $adapter->data()->all());
        $this->assertSame(250, $adapter->code());
        $this->assertSame('OK', $adapter->response());
    }

    public function testOccurredAt()
    {
        $adapter = new Mailgun($this->deliveredPayload());

        $date = $adapter->occurredAt();

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame(1609459200, $date->getTimestamp());
        $this->assertSame('2021-01-01T00:00:00+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    public function testResponseFallsBackToMessage()
    {
        $payload = $this->deliveredPayload();
        unset($payload['event-data']['delivery-status']['description']);

        $adapter = new Mailgun($payload);

        $this->assertSame('queued as ABC', $adapter->response());
    }

    public function testParsesFailedEvent()
    {
        $payload = $this->deliveredPayload();
        $payload['event-data']['event'] = 'failed';
        $payload['event-data']['reason'] = 'bounce';

        $adapter = new Mailgun($payload);

        $this->assertSame(EmailEvent::STATUS_BOUNCED, $adapter->status());
        $this->assertSame('bounce', $adapter->reason());
    }

    public function testUnknownEventIsInvalid()
    {
        $payload = $this->deliveredPayload();
        $payload['event-data']['event'] = 'unsubscribed';

        $adapter = new Mailgun($payload);

        $this->assertNull($adapter->status());
        $this->assertFalse($adapter->isValid());
        $this->assertNull(EmailEvent::create($adapter));
    }

    public function testEmailEventToArray()
    {
        $event = EmailEvent::create(new Mailgun($this->deliveredPayload()));

        $this->assertInstanceOf(EmailEvent::class, $event);
        $this->assertSame([
            'provider'            => 'Mailgun',
            'status'              => EmailEvent::STATUS_DELIVERED,
            'provider_message_id' => 'mailgun-message-1@example.com',
            'to_address'          => 'recipient@example.com',
            'occurred_at'         => '2021-01-01T00:00:00+00:00',
            'bounce_type'         => null,
            'reason'              => null,
            'response'            => 'OK',
            'code'                => 250,
            'clicked_url'         => null,
            'tags'                => ['welcome', 'newsletter'],
            'data'                => ['order_id' => '1234'],
        ], $event->toArray());
    }

    public function testBounceTypeNullForNonBounce()
    {
        $adapter = new Mailgun($this->deliveredPayload());

        $this->assertNull($adapter->bounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testPermanentFailureClassifiedAsHard()
    {
        $payload = $this->deliveredPayload();
        $payload['event-data']['event'] = 'failed';
        $payload['event-data']['severity'] = 'permanent';

        $adapter = new Mailgun($payload);

        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->bounceType());
        $this->assertTrue($adapter->isPermanent());
    }

    public function testTemporaryFailureClassifiedAsSoft()
    {
        $payload = $this->deliveredPayload();
        $payload['event-data']['event'] = 'failed';
        $payload['event-data']['severity'] = 'temporary';

        $adapter = new Mailgun($payload);

        $this->assertSame(EmailEvent::BOUNCE_SOFT, $adapter->bounceType());
        $this->assertFalse($adapter->isPermanent());
    }
}
