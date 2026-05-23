<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\Resend\Adapter as Resend;

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
        $this->assertSame('Resend', $adapter->provider());
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $adapter->status());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame(1609459200, $adapter->occurredAt()->getTimestamp());
        $this->assertSame('resend-message-1', $adapter->providerMessageId());
        $this->assertSame(['welcome'], $adapter->tags()->all());
        $this->assertNull($adapter->bounceType());
    }

    public function testParsesBouncedEvent()
    {
        $adapter = new Resend($this->bouncedPayload());

        $this->assertSame(EmailEvent::STATUS_BOUNCED, $adapter->status());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->bounceType());
        $this->assertTrue($adapter->isPermanent());
        $this->assertSame('General', $adapter->reason());
        $this->assertSame('The recipient mailbox does not exist', $adapter->response());
    }

    public function testTransientBounceIsSoft()
    {
        $payload = $this->bouncedPayload();
        $payload['data']['bounce']['type'] = 'Transient';

        $adapter = new Resend($payload);

        $this->assertSame(EmailEvent::BOUNCE_SOFT, $adapter->bounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testUnknownEventIsInvalid()
    {
        $payload = $this->deliveredPayload();
        $payload['type'] = 'email.something_new';

        $adapter = new Resend($payload);

        $this->assertNull($adapter->status());
        $this->assertFalse($adapter->isValid());
        $this->assertNull(EmailEvent::create($adapter));
    }

    public function testEmailEventToArray()
    {
        $event = EmailEvent::create(new Resend($this->deliveredPayload()));

        $this->assertInstanceOf(EmailEvent::class, $event);
        $this->assertSame([
            'provider'            => 'Resend',
            'status'              => EmailEvent::STATUS_DELIVERED,
            'provider_message_id' => 'resend-message-1',
            'to_address'          => 'recipient@example.com',
            'occurred_at'         => '2021-01-01T00:00:00+00:00',
            'bounce_type'         => null,
            'reason'              => null,
            'response'            => null,
            'code'                => null,
            'clicked_url'         => null,
            'tags'                => ['welcome'],
            'data'                => [],
        ], $event->toArray());
    }
}
