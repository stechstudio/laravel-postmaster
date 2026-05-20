<?php

namespace STS\Postmaster\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserAgentAuth
{
    /**
     * @param Request $request
     * @param         $adapterClass
     *
     * @return bool
     */
    public function __invoke(Request $request, $adapterClass)
    {
        return Str::is($adapterClass::getUserAgent(), $request->userAgent());
    }
}