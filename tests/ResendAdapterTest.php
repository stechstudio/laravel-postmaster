<?php

namespace STS\EmailEvents\Tests;

use STS\EmailEvents\EmailEvent;
use STS\EmailEvents\Providers\Resend\Adapter as Resend;

class ResendAdapterTest extends TestCase
{
    protected function deliveredPayload(): array
    {
        return [
            'type'       => 'email.delivered',
            'created_at' => '2021-01-01T00:00:00.000Z',
            'data'       => [
                'email_id' => 'resend-message-1',
                'from'     => 'sender@example.com',
                'to'       => ['recipient@example.com'],
                'subject'  => 'Test',
                'tags'     => ['welcome'],
            ],
        ];
    }

    protected function bouncedPayload(): array
    {
        return [
            'type'       => 'email.bounced',
            'created_at' => '2021-01-01T00:00:00.000Z',
            'data'       => [
                'email_id' => 'resend-message-2',
                'to'       => ['recipient@example.com'],
                'bounce'   => [
                    'type'    => 'Permanent',
                    'subType' => 'General',
                    'message' => 'The recipient mailbox does not exist',
                ],
            ],
        ];
    }

    public function testSupports()
    {
        $this->assertTrue(Resend::supports($this->deliveredPayload()));
        $this->assertFalse(Resend::supports(['type' => 'contact.created', 'data' => []]));
        $this->assertFalse(Resend::supports(['data' => []]));
    }

    public function testParsesDeliveredEvent()
    {
        $adapter = new Resend($this->deliveredPayload());

        $this->assertTrue($adapter->isValid());
        $this->assertSame('Resend', $adapter->getProvider());
        $this->assertSame(EmailEvent::EVENT_DELIVERED, $adapter->getAction());
        $this->assertSame('recipient@example.com', $adapter->getRecipient());
        $this->assertSame(1609459200, $adapter->getTimestamp());
        $this->assertSame('resend-message-1', $adapter->getMessageId());
        $this->assertSame(['welcome'], $adapter->getTags()->all());
        $this->assertNull($adapter->getBounceType());
    }

    public function testParsesBouncedEvent()
    {
        $adapter = new Resend($this->bouncedPayload());

        $this->assertSame(EmailEvent::EVENT_BOUNCED, $adapter->getAction());
        $this->assertSame('recipient@example.com', $adapter->getRecipient());
        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->getBounceType());
        $this->assertTrue($adapter->isPermanent());
        $this->assertSame('General', $adapter->getReason());
        $this->assertSame('The recipient mailbox does not exist', $adapter->getResponse());
    }

    public function testTransientBounceIsSoft()
    {
        $payload = $this->bouncedPayload();
        $payload['data']['bounce']['type'] = 'Transient';

        $adapter = new Resend($payload);

        $this->assertSame(EmailEvent::BOUNCE_SOFT, $adapter->getBounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testUnknownEventIsInvalid()
    {
        $payload = $this->deliveredPayload();
        $payload['type'] = 'email.something_new';

        $adapter = new Resend($payload);

        $this->assertNull($adapter->getAction());
        $this->assertFalse($adapter->isValid());
        $this->assertNull(EmailEvent::create($adapter));
    }

    public function testEmailEventToArray()
    {
        $event = EmailEvent::create(new Resend($this->deliveredPayload()));

        $this->assertInstanceOf(EmailEvent::class, $event);
        $this->assertSame([
            'provider'   => 'Resend',
            'event'      => EmailEvent::EVENT_DELIVERED,
            'timestamp'  => 1609459200,
            'date'       => '2021-01-01T00:00:00+00:00',
            'recipient'  => 'recipient@example.com',
            'messageId'  => 'resend-message-1',
            'tags'       => ['welcome'],
            'data'       => [],
            'response'   => null,
            'reason'     => null,
            'code'       => null,
            'bounceType' => null,
        ], $event->toArray());
    }
}
