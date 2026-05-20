<?php

namespace STS\Postmaster\Tests;

use Illuminate\Support\Facades\Log;
use STS\Postmaster\Providers\SendGrid\Adapter as SendGrid;
use STS\Postmaster\Exceptions\InvalidEventException;
use STS\Postmaster\Provider;

class ProviderTest extends TestCase
{
    protected function provider($onInvalid)
    {
        return new Provider('sendgrid', SendGrid::class, fn() => true, $onInvalid);
    }

    protected function validPayload()
    {
        return [
            'email'         => 'recipient@example.com',
            'event'         => 'delivered',
            'timestamp'     => 1609459200,
            'smtp-id'       => '<message-id@example.com>',
            'sg_message_id' => 'sg-message-1',
        ];
    }

    protected function invalidPayload()
    {
        $payload = $this->validPayload();
        $payload['event'] = 'not-a-real-event';

        return $payload;
    }

    public function testValidPayloadProducesAnEvent()
    {
        $events = $this->provider('log')->adapt($this->validPayload())->getEvents();

        $this->assertCount(1, $events);
    }

    public function testInvalidPayloadIsLoggedByDefault()
    {
        Log::spy();

        $events = $this->provider('log')->adapt($this->invalidPayload())->getEvents();

        $this->assertCount(0, $events);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Dropped invalid email event payload', \Mockery::type('array'));
    }

    public function testInvalidPayloadCanThrow()
    {
        $this->expectException(InvalidEventException::class);

        $this->provider('throw')->adapt($this->invalidPayload());
    }

    public function testInvalidPayloadCanBeIgnoredSilently()
    {
        Log::spy();

        $events = $this->provider('ignore')->adapt($this->invalidPayload())->getEvents();

        $this->assertCount(0, $events);
        Log::shouldNotHaveReceived('warning');
    }

    public function testThrownExceptionCarriesThePayload()
    {
        try {
            $this->provider('throw')->adapt($this->invalidPayload());
            $this->fail('Expected InvalidEventException was not thrown');
        } catch (InvalidEventException $e) {
            $this->assertSame($this->invalidPayload(), $e->getPayload());
        }
    }
}
