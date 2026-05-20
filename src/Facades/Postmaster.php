<?php

namespace STS\Postmaster\Facades;

use Illuminate\Support\Facades\Facade;

class Postmaster extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'postmaster';
    }
}
