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
                'id'              => 'mailgun-message-1',
                'recipient'       => 'recipient@example.com',
                'timestamp'       => 1609459200,
                'tags'            => ['welcome', 'newsletter'],
                'user-variables'  => ['order_id' => '1234'],
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
        $this->assertSame('Mailgun', $adapter->getProvider());
        $this->assertSame(EmailEvent::EVENT_DELIVERED, $adapter->getAction());
        $this->assertSame('recipient@example.com', $adapter->getRecipient());
        $this->assertSame(1609459200, $adapter->getTimestamp());
        $this->assertSame('mailgun-message-1', $adapter->getMessageId());
        $this->assertSame(['welcome', 'newsletter'], $adapter->getTags()->all());
        $this->assertSame(['order_id' => '1234'], $adapter->getData()->all());
        $this->assertSame(250, $adapter->getCode());
        $this->assertSame('OK', $adapter->getResponse());
    }

    public function testGetDate()
    {
        $adapter = new Mailgun($this->deliveredPayload());

        $date = $adapter->getDate();

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame(1609459200, $date->getTimestamp());
        $this->assertSame('2021-01-01T00:00:00+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    public function testResponseFallsBackToMessage()
    {
        $payload = $this->deliveredPayload();
        unset($payload['event-data']['delivery-status']['description']);

        $adapter = new Mailgun($payload);

        $this->assertSame('queued as ABC', $adapter->getResponse());
    }

    public function testParsesFailedEvent()
    {
        $payload = $this->deliveredPayload();
        $payload['event-data']['event'] = 'failed';
        $payload['event-data']['reason'] = 'bounce';

        $adapter = new Mailgun($payload);

        $this->assertSame(EmailEvent::EVENT_BOUNCED, $adapter->getAction());
        $this->assertSame('bounce', $adapter->getReason());
    }

    public function testUnknownEventIsInvalid()
    {
        $payload = $this->deliveredPayload();
        $payload['event-data']['event'] = 'unsubscribed';

        $adapter = new Mailgun($payload);

        $this->assertNull($adapter->getAction());
        $this->assertFalse($adapter->isValid());
        $this->assertNull(EmailEvent::create($adapter));
    }

    public function testEmailEventToArray()
    {
        $event = EmailEvent::create(new Mailgun($this->deliveredPayload()));

        $this->assertInstanceOf(EmailEvent::class, $event);
        $this->assertSame([
            'provider'  => 'Mailgun',
            'event'     => EmailEvent::EVENT_DELIVERED,
            'timestamp' => 1609459200,
            'date'      => '2021-01-01T00:00:00+00:00',
            'recipient' => 'recipient@example.com',
            'messageId' => 'mailgun-message-1',
            'tags'      => ['welcome', 'newsletter'],
            'data'      => ['order_id' => '1234'],
            'response'  => 'OK',
            'reason'    => null,
            'code'      => 250,
            'bounceType' => null,
        ], $event->toArray());
    }

    public function testBounceTypeNullForNonBounce()
    {
        $adapter = new Mailgun($this->deliveredPayload());

        $this->assertNull($adapter->getBounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testPermanentFailureClassifiedAsHard()
    {
        $payload = $this->deliveredPayload();
        $payload['event-data']['event'] = 'failed';
        $payload['event-data']['severity'] = 'permanent';

        $adapter = new Mailgun($payload);

        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->getBounceType());
        $this->assertTrue($adapter->isPermanent());
    }

    public function testTemporaryFailureClassifiedAsSoft()
    {
        $payload = $this->deliveredPayload();
        $payload['event-data']['event'] = 'failed';
        $payload['event-data']['severity'] = 'temporary';

        $adapter = new Mailgun($payload);

        $this->assertSame(EmailEvent::BOUNCE_SOFT, $adapter->getBounceType());
        $this->assertFalse($adapter->isPermanent());
    }
}
