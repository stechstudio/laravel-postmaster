<?php

namespace STS\EmailEvents\Tests;

use STS\EmailEvents\EmailEvent;
use STS\EmailEvents\Providers\Ses\Adapter as Ses;

class SesAdapterTest extends TestCase
{
    protected function envelope(array $sesEvent): array
    {
        return [
            'Type'      => 'Notification',
            'MessageId' => 'sns-message-1',
            'TopicArn'  => 'arn:aws:sns:us-east-1:123456789012:ses-events',
            'Message'   => json_encode($sesEvent),
            'Timestamp' => '2021-01-01T00:00:00.000Z',
        ];
    }

    protected function deliveryEvent(): array
    {
        return [
            'eventType' => 'Delivery',
            'mail'      => [
                'timestamp'   => '2021-01-01T00:00:00.000Z',
                'messageId'   => 'ses-message-1',
                'destination' => ['recipient@example.com'],
                'tags'        => ['campaign' => ['welcome']],
            ],
            'delivery' => ['recipients' => ['recipient@example.com']],
        ];
    }

    protected function bounceEvent(): array
    {
        return [
            'eventType' => 'Bounce',
            'mail'      => [
                'timestamp'   => '2021-01-01T00:00:00.000Z',
                'messageId'   => 'ses-message-2',
                'destination' => ['recipient@example.com'],
            ],
            'bounce' => [
                'bounceType'    => 'Permanent',
                'bounceSubType' => 'General',
                'bouncedRecipients' => [[
                    'emailAddress'   => 'recipient@example.com',
                    'diagnosticCode' => 'smtp; 550 5.1.1 user unknown',
                    'status'         => '5.1.1',
                ]],
            ],
        ];
    }

    public function testSupports()
    {
        $this->assertTrue(Ses::supports($this->envelope($this->deliveryEvent())));
        $this->assertTrue(Ses::supports($this->deliveryEvent()));
        $this->assertFalse(Ses::supports(['Type' => 'SubscriptionConfirmation']));
    }

    public function testParsesDeliveryEvent()
    {
        $adapter = new Ses($this->envelope($this->deliveryEvent()));

        $this->assertTrue($adapter->isValid());
        $this->assertSame('SES', $adapter->getProvider());
        $this->assertSame(EmailEvent::EVENT_DELIVERED, $adapter->getAction());
        $this->assertSame('recipient@example.com', $adapter->getRecipient());
        $this->assertSame(1609459200, $adapter->getTimestamp());
        $this->assertSame('ses-message-1', $adapter->getMessageId());
        $this->assertSame(['campaign'], $adapter->getTags()->all());
        $this->assertNull($adapter->getBounceType());
    }

    public function testParsesBounceEvent()
    {
        $adapter = new Ses($this->envelope($this->bounceEvent()));

        $this->assertSame(EmailEvent::EVENT_BOUNCED, $adapter->getAction());
        $this->assertSame('recipient@example.com', $adapter->getRecipient());
        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->getBounceType());
        $this->assertTrue($adapter->isPermanent());
        $this->assertSame('General', $adapter->getReason());
        $this->assertSame('smtp; 550 5.1.1 user unknown', $adapter->getResponse());
        $this->assertSame('5.1.1', $adapter->getCode());
    }

    public function testTransientBounceIsSoft()
    {
        $event = $this->bounceEvent();
        $event['bounce']['bounceType'] = 'Transient';

        $adapter = new Ses($this->envelope($event));

        $this->assertSame(EmailEvent::BOUNCE_SOFT, $adapter->getBounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testSupportsTheOlderNotificationTypeFormat()
    {
        $event = $this->deliveryEvent();
        unset($event['eventType']);
        $event['notificationType'] = 'Delivery';

        $adapter = new Ses($this->envelope($event));

        $this->assertSame(EmailEvent::EVENT_DELIVERED, $adapter->getAction());
    }

    public function testSubscriptionConfirmationIsNotAValidEvent()
    {
        $adapter = new Ses([
            'Type'        => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
        ]);

        $this->assertFalse($adapter->isValid());
        $this->assertNull(EmailEvent::create($adapter));
    }

    public function testEmailEventToArray()
    {
        $event = EmailEvent::create(new Ses($this->envelope($this->deliveryEvent())));

        $this->assertInstanceOf(EmailEvent::class, $event);
        $this->assertSame(EmailEvent::EVENT_DELIVERED, $event->toArray()['event']);
        $this->assertSame('2021-01-01T00:00:00+00:00', $event->toArray()['date']);
    }
}
