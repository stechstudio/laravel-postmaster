<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\Providers\Postmark\Adapter as Postmark;
use STS\Postmaster\EmailEvent;

class PostmarkAdapterTest extends TestCase
{
    protected function deliveryPayload()
    {
        return [
            'RecordType'  => 'Delivery',
            'MessageID'   => 'postmark-message-1',
            'Recipient'   => 'recipient@example.com',
            'DeliveredAt' => '2021-01-01T00:00:00Z',
            'Details'     => 'smtp;250 OK',
            'Tag'         => 'welcome',
            'Metadata'    => ['order_id' => '1234'],
        ];
    }

    protected function bouncePayload()
    {
        return [
            'RecordType' => 'Bounce',
            'Type'       => 'HardBounce',
            'TypeCode'   => 1,
            'MessageID'  => 'postmark-message-2',
            'Email'      => 'recipient@example.com',
            'BouncedAt'  => '2021-01-01T00:00:00Z',
            'Details'    => 'mailbox does not exist',
        ];
    }

    public function testSupports()
    {
        $this->assertTrue(Postmark::supports($this->deliveryPayload()));
        $this->assertFalse(Postmark::supports(['MessageID' => 'x']));
        $this->assertFalse(Postmark::supports(['RecordType' => 'Delivery']));
    }

    public function testParsesDeliveryEvent()
    {
        $adapter = new Postmark($this->deliveryPayload());

        $this->assertTrue($adapter->isValid());
        $this->assertSame('Postmark', $adapter->provider());
        $this->assertSame(EmailEvent::STATUS_DELIVERED, $adapter->status());
        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertSame(strtotime('2021-01-01T00:00:00Z'), $adapter->occurredAt()->getTimestamp());
        $this->assertSame('postmark-message-1', $adapter->providerMessageId());
        $this->assertSame('smtp;250 OK', $adapter->response());
        $this->assertSame(['welcome'], $adapter->tags()->all());
        $this->assertSame(['order_id' => '1234'], $adapter->data()->all());
        $this->assertNull($adapter->code());
    }

    public function testOccurredAt()
    {
        $adapter = new Postmark($this->deliveryPayload());

        $date = $adapter->occurredAt();

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2021-01-01T00:00:00+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    public function testOccurredAtIsNullWithoutTimestamp()
    {
        $payload = $this->deliveryPayload();
        unset($payload['DeliveredAt']);

        $adapter = new Postmark($payload);

        $this->assertNull($adapter->occurredAt());
        $this->assertNull($adapter->occurredAt());
    }

    public function testParsesBounceEvent()
    {
        $adapter = new Postmark($this->bouncePayload());

        $this->assertSame(EmailEvent::STATUS_BOUNCED, $adapter->status());
        $this->assertSame('HardBounce', $adapter->reason());
        $this->assertSame(1, $adapter->code());
    }

    public function testBounceRecipientFallsBackToEmailField()
    {
        $adapter = new Postmark($this->bouncePayload());

        $this->assertSame('recipient@example.com', $adapter->toAddress());
        $this->assertTrue($adapter->isValid());
        $this->assertInstanceOf(EmailEvent::class, EmailEvent::create($adapter));
    }

    public function testTransientBounceMapsToDeferred()
    {
        $payload = $this->bouncePayload();
        $payload['Type'] = 'Transient';

        $adapter = new Postmark($payload);

        $this->assertSame(EmailEvent::STATUS_DEFERRED, $adapter->status());
    }

    public function testUnknownRecordTypeIsInvalid()
    {
        $payload = $this->deliveryPayload();
        $payload['RecordType'] = 'SubscriptionChange';

        $adapter = new Postmark($payload);

        $this->assertNull($adapter->status());
        $this->assertFalse($adapter->isValid());
        $this->assertNull(EmailEvent::create($adapter));
    }

    public function testEmailEventToArray()
    {
        $event = EmailEvent::create(new Postmark($this->deliveryPayload()));

        $this->assertInstanceOf(EmailEvent::class, $event);
        $this->assertSame([
            'provider'            => 'Postmark',
            'status'              => EmailEvent::STATUS_DELIVERED,
            'provider_message_id' => 'postmark-message-1',
            'to_address'          => 'recipient@example.com',
            'occurred_at'         => '2021-01-01T00:00:00+00:00',
            'bounce_type'         => null,
            'reason'              => null,
            'response'            => 'smtp;250 OK',
            'code'                => null,
            'clicked_url'         => null,
            'tags'                => ['welcome'],
            'data'                => ['order_id' => '1234'],
        ], $event->toArray());
    }

    public function testBounceTypeNullForNonBounce()
    {
        $adapter = new Postmark($this->deliveryPayload());

        $this->assertNull($adapter->bounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testHardBounceClassification()
    {
        $adapter = new Postmark($this->bouncePayload());

        $this->assertSame(EmailEvent::BOUNCE_HARD, $adapter->bounceType());
        $this->assertTrue($adapter->isPermanent());
    }

    public function testSoftBounceClassification()
    {
        $payload = $this->bouncePayload();
        $payload['Type'] = 'SoftBounce';

        $adapter = new Postmark($payload);

        $this->assertSame(EmailEvent::BOUNCE_SOFT, $adapter->bounceType());
        $this->assertFalse($adapter->isPermanent());
    }

    public function testBlockedBounceClassification()
    {
        $payload = $this->bouncePayload();
        $payload['Type'] = 'Blocked';

        $adapter = new Postmark($payload);

        $this->assertSame(EmailEvent::BOUNCE_BLOCK, $adapter->bounceType());
        $this->assertTrue($adapter->isPermanent());
    }

    public function testUnknownBounceTypeDefaultsToSoft()
    {
        $payload = $this->bouncePayload();
        $payload['Type'] = 'SomeFutureBounceType';

        $adapter = new Postmark($payload);

        $this->assertSame(EmailEvent::BOUNCE_SOFT, $adapter->bounceType());
        $this->assertFalse($adapter->isPermanent());
    }
}
