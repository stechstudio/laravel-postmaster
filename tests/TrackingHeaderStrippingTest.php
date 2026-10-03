<?php

namespace STS\Postmaster\Tests;

use Illuminate\Support\Facades\Mail;

/** Postmaster's tracking headers are internal and must never reach a provider. */
class TrackingHeaderStrippingTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('postmaster.persistence.enabled', false);
        $app['config']->set('mail.default', 'array');
    }

    public function testStripsKnownAndUnknownTrackingHeaders(): void
    {
        Mail::raw('Hello', function ($message) {
            $message->to('a@example.com')->subject('Hi');
            $headers = $message->getSymfonyMessage()->getHeaders();
            $headers->addTextHeader('X-Postmaster-Tenant', '42');
            $headers->addTextHeader('X-Postmaster-Something-New', 'internal');
            $headers->addTextHeader('X-Custom', 'keep');
        });

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $this->assertFalse($sent->getHeaders()->has('X-Postmaster-Tenant'));
        $this->assertFalse($sent->getHeaders()->has('X-Postmaster-Something-New'));
        $this->assertTrue($sent->getHeaders()->has('X-Custom'));
    }
}
