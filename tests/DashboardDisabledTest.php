<?php

namespace STS\Postmaster\Tests;

class DashboardDisabledTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('postmaster.persistence.enabled', true);
        $app['config']->set('postmaster.dashboard.enabled', false);
    }

    public function testDashboardRoutesAreNotRegisteredWhenDisabled()
    {
        $this->get('/postmaster')->assertNotFound();
    }
}
