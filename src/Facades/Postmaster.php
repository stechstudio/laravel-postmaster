<?php

namespace STS\Postmaster\Facades;

use Illuminate\Support\Facades\Facade;

class Postmaster extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'postmaster';
    }
}
