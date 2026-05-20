<?php

namespace STS\EmailEvents\Tests;

use Illuminate\Support\Facades\Route;

class WebhookRouteDisabledTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('email-events.register_route', false);
    }

    public function testWebhookRouteIsNotRegisteredWhenDisabled()
    {
        $this->assertFalse(Route::has('webhook.email-events'));
    }
}
