<?php

namespace STS\Postmaster\Tests;

use STS\Postmaster\PostmasterServiceProvider;
use STS\Postmaster\Facades\Postmaster;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
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