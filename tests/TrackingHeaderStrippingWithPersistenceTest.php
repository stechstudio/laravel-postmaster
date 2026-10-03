<?php

namespace STS\Postmaster\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Tests\Stubs\Order;
use STS\Postmaster\Tests\Stubs\OrderConfirmationMail;

/** The same rule with persistence on, where the headers also feed the record. */
class TrackingHeaderStrippingWithPersistenceTest extends TrackingHeaderStrippingTest
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    public function testMetadataStillReachesTheRecord(): void
    {
        Schema::create('orders', fn ($table) => $table->id());
        $order = Order::create();

        Mail::to('a@example.com')->send((new OrderConfirmationMail($order))->withSymfonyMessage(
            fn ($message) => $message->getHeaders()->addTextHeader('X-Postmaster-Something-New', 'internal'),
        ));

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertFalse($sent->getHeaders()->has('X-Postmaster-Related-Id'));
        $this->assertFalse($sent->getHeaders()->has('X-Postmaster-Something-New'));
        $this->assertTrue(EmailMessage::sole()->related->is($order));
    }
}
