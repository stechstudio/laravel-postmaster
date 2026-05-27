<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\Providers\SendGrid\Adapter as SendGrid;
use STS\Postmaster\EmailEvent;

class SendGridAdapterTest extends TestCase
{
    protected function deliveredPayload()
    {
        return [
            'email'         => 'recipient@example.com',
            'event'         => 'delivered',
            'timestamp'     => 1609459200,
            'smtp-id'       => 'sg-message-1',
            'sg_event_id'   => 'sg-event-1',
            'sg_message_id' => 'sg-message-1',
            'status'        => '2.0.0',
            'response'      => '250 OK',
            'category'      => ['newsletter', 'welcome'],
            'custom_field'  => 'custom_value',
        ];
    }

    public function testSupports()
    {
        $this->assertTrue(SendGrid::supports($this->deliveredPayload()));
        $this->assertFalse(SendGrid::supports(['email' => 'recipient@example.com']));
    }

    public function testParsesDeliveredEvent()
    {
        $adapter = new SendGrid($this->deliveredPayload());

        $this->assertTrue($adapter->isValid());
        $this->assertSame('SendGrid', $adapter->provider());
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $adapter->status());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame(1609459200, $adapter->occurredAt()->getTimestamp());
        $this->assertSame('sg-message-1', $adapter->providerMessageId());
        $this->assertSame('250 OK', $adapter->response());
        $this->assertSame('2.0.0', $adapter->code());
    }

    public function testClickEventCarriesTheClickedUrl()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'click';
        $payload['url']   = 'https://example.com/promo';

        $adapter = new SendGrid($payload);

        $this->assertSame(EmailEvent::STATUS_CLICKED, $adapter->status());
        $this->assertSame('https://example.com/promo', $adapter->clickedUrl());
    }

    public function testNonClickEventHasNoUrl()
    {
        $adapter = new SendGrid($this->deliveredPayload());

        $this->assertNull($adapter->clickedUrl());
    }

    public function testOccurredAt()
    {
        $adapter = new SendGrid($this->deliveredPayload());

        $date = $adapter->occurredAt();

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame(1609459200, $date->getTimestamp());
        $this->assertSame('2021-01-01T00:00:00+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    public function testTagsAndCustomData()
    {
        $adapter = new SendGrid($this->deliveredPayload());

        $this->assertSame(['newsletter', 'welcome'], $adapter->tags()->all());
        $this->assertSame(['custom_field' => 'custom_value'], $adapter->data()->all());
    }

    public function testMapsBounceEvent()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'bounce';
        $payload['reason'] = '550 mailbox unavailable';

        $adapter = new SendGrid($payload);

        $this->assertSame(EmailEvent::STATUS_BOUNCED, $adapter->status());
        $this->assertSame('550 mailbox unavailable', $adapter->reason());
    }

    public function testUnknownEventIsInvalid()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'not-a-real-event';

        $adapter = new SendGrid($payload);

        $this->assertNull($adapter->status());
        $this->assertFalse($adapter->isValid());
        $this->assertNull(EmailEvent::create($adapter));
    }

    public function testEmailEventToArray()
    {
        $event = EmailEvent::create(new SendGrid($this->deliveredPayload()));

        $this->assertInstanceOf(EmailEvent::class, $event);
        $this->assertSame([
            'provider'            => 'SendGrid',
            'status'              => EmailEvent::STATUS_DELIVERED,
            'provider_message_id' => 'sg-message-1',
            'to_address'          => 'recipient@example.com',
            'occurred_at'         => '2021-01-01T00:00:00+00:00',
            'bounce_type'         => null,
            'reason'              => null,
            'response'            => '250 OK',
            'code'                => '2.0.0',
            'clicked_url'         => null,
            'tags'                => ['newsletter', 'welcome'],
            'data'                => ['custom_field' => 'custom_value'],
        ], $event->toArray());
    }

    public function testBounceTypeNullForNonBounce()
    {
        $adapter = new SendGrid($this->deliveredPayload());

        $this->assertNull($adapter->bounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testHardBounceClassification()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'bounce';
        $payload['type'] = 'bounce';

        $adapter = new SendGrid($payload);

        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->bounceType());
        $this->assertTrue($adapter->isPermanent());
    }

    public function testBlockedBounceClassification()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'bounce';
        $payload['type'] = 'blocked';

        $adapter = new SendGrid($payload);

        $this->assertSame(EmailEvent::BOUNCE_BLOCK, $adapter->bounceType());
        $this->assertTrue($adapter->isPermanent());
    }

    public function testDroppedEventClassifiedAsHard()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'dropped';

        $adapter = new SendGrid($payload);

        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->bounceType());
        $this->assertTrue($adapter->isPermanent());
    }
}
