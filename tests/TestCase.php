<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\PostmasterServiceProvider;
use STS\Postmaster\Facades\Postmaster;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function defineEnvironment($app)
    {
        // Pin cache and session to the in-memory driver so the suite does not
        // depend on the skeleton app's defaults (which use the database).
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
    }

    protected function getPackageProviders($app)
    {
        return [PostmasterServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Postmaster' => Postmaster::class
        ];
    }
}