<?php

namespace STS\Postmaster\Tests;

use Illuminate\Support\Facades\Route;

class WebhookRouteDisabledTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.register_route', false);
    }

    public function testWebhookRouteIsNotRegisteredWhenDisabled()
    {
        $this->assertFalse(Route::has('webhook.postmaster'));
    }
}
