<?php

namespace STS\EmailEvents\Tests;

use STS\EmailEvents\Providers\SendGrid\Adapter as SendGrid;
use STS\EmailEvents\EmailEvent;

class SendGridAdapterTest extends TestCase
{
    protected function deliveredPayload()
    {
        return [
            'email'         => 'recipient@example.com',
            'event'         => 'delivered',
            'timestamp'     => 1609459200,
            'smtp-id'       => '<message-id@example.com>',
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
        $this->assertSame('SendGrid', $adapter->getProvider());
        $this->assertSame(EmailEvent::EVENT_DELIVERED, $adapter->getAction());
        $this->assertSame('recipient@example.com', $adapter->getRecipient());
        $this->assertSame(1609459200, $adapter->getTimestamp());
        $this->assertSame('<message-id@example.com>', $adapter->getMessageId());
        $this->assertSame('250 OK', $adapter->getResponse());
        $this->assertSame('2.0.0', $adapter->getCode());
    }

    public function testGetDate()
    {
        $adapter = new SendGrid($this->deliveredPayload());

        $date = $adapter->getDate();

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame(1609459200, $date->getTimestamp());
        $this->assertSame('2021-01-01T00:00:00+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    public function testTagsAndCustomData()
    {
        $adapter = new SendGrid($this->deliveredPayload());

        $this->assertSame(['newsletter', 'welcome'], $adapter->getTags()->all());
        $this->assertSame(['custom_field' => 'custom_value'], $adapter->getData()->all());
    }

    public function testMapsBounceEvent()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'bounce';
        $payload['reason'] = '550 mailbox unavailable';

        $adapter = new SendGrid($payload);

        $this->assertSame(EmailEvent::EVENT_BOUNCED, $adapter->getAction());
        $this->assertSame('550 mailbox unavailable', $adapter->getReason());
    }

    public function testUnknownEventIsInvalid()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'not-a-real-event';

        $adapter = new SendGrid($payload);

        $this->assertNull($adapter->getAction());
        $this->assertFalse($adapter->isValid());
        $this->assertNull(EmailEvent::create($adapter));
    }

    public function testEmailEventToArray()
    {
        $event = EmailEvent::create(new SendGrid($this->deliveredPayload()));

        $this->assertInstanceOf(EmailEvent::class, $event);
        $this->assertSame([
            'provider'  => 'SendGrid',
            'event'     => EmailEvent::EVENT_DELIVERED,
            'timestamp' => 1609459200,
            'date'      => '2021-01-01T00:00:00+00:00',
            'recipient' => 'recipient@example.com',
            'messageId' => '<message-id@example.com>',
            'tags'      => ['newsletter', 'welcome'],
            'data'      => ['custom_field' => 'custom_value'],
            'response'  => '250 OK',
            'reason'    => null,
            'code'      => '2.0.0',
            'bounceType' => null,
        ], $event->toArray());
    }

    public function testBounceTypeNullForNonBounce()
    {
        $adapter = new SendGrid($this->deliveredPayload());

        $this->assertNull($adapter->getBounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testHardBounceClassification()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'bounce';
        $payload['type'] = 'bounce';

        $adapter = new SendGrid($payload);

        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->getBounceType());
        $this->assertTrue($adapter->isPermanent());
    }

    public function testBlockedBounceClassification()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'bounce';
        $payload['type'] = 'blocked';

        $adapter = new SendGrid($payload);

        $this->assertSame(EmailEvent::BOUNCE_BLOCK, $adapter->getBounceType());
        $this->assertTrue($adapter->isPermanent());
    }

    public function testDroppedEventClassifiedAsHard()
    {
        $payload = $this->deliveredPayload();
        $payload['event'] = 'dropped';

        $adapter = new SendGrid($payload);

        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->getBounceType());
        $this->assertTrue($adapter->isPermanent());
    }
}
