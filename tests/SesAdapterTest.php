<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\Ses\Adapter as Ses;

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
        $this->assertSame('SES', $adapter->provider());
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $adapter->status());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame(1609459200, $adapter->occurredAt()->getTimestamp());
        $this->assertSame('ses-message-1', $adapter->providerMessageId());
        $this->assertSame(['campaign'], $adapter->tags()->all());
        $this->assertNull($adapter->bounceType());
    }

    public function testParsesBounceEvent()
    {
        $adapter = new Ses($this->envelope($this->bounceEvent()));

        $this->assertSame(EmailEvent::STATUS_BOUNCED, $adapter->status());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->bounceType());
        $this->assertTrue($adapter->isPermanent());
        $this->assertSame('General', $adapter->reason());
        $this->assertSame('smtp; 550 5.1.1 user unknown', $adapter->response());
        $this->assertSame('5.1.1', $adapter->code());
    }

    public function testTransientBounceIsSoft()
    {
        $event = $this->bounceEvent();
        $event['bounce']['bounceType'] = 'Transient';

        $adapter = new Ses($this->envelope($event));

        $this->assertSame(EmailEvent::BOUNCE_SOFT, $adapter->bounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testSupportsTheOlderNotificationTypeFormat()
    {
        $event = $this->deliveryEvent();
        unset($event['eventType']);
        $event['notificationType'] = 'Delivery';

        $adapter = new Ses($this->envelope($event));

        $this->assertSame(EmailEvent::STATUS_DELIVERED, $adapter->status());
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
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $event->toArray()['status']);
        $this->assertSame('2021-01-01T00:00:00+00:00', $event->toArray()['occurred_at']);
    }
}
