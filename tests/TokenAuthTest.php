<?php

namespace STS\Postmaster\Tests;

use Illuminate\Http\Request;
use STS\Postmaster\Auth\TokenAuth;

class TokenAuthTest extends TestCase
{
    public function testSimpleToken()
    {
        config([
            'postmaster.token' => 'mysupersecrettoken',
        ]);

        $auth = resolve(TokenAuth::class);
        $request = Request::capture();
        $this->assertFalse($auth($request));

        $request->offsetSet('auth', 'invalidtoken');
        $this->assertFalse($auth($request));

        $request->offsetSet('invalidlocation', 'mysupersecrettoken');
        $this->assertFalse($auth($request));

        $request->offsetSet('auth', 'mysupersecrettoken');
        $this->assertTrue($auth($request));
    }

    public function testRejectsWhenNoTokenIsConfigured()
    {
        config(['postmaster.token' => null]);

        // Without this guard, the loose comparison `null == null` against an
        // un-supplied query parameter would accept any unauthenticated request.
        $this->assertFalse(resolve(TokenAuth::class)(Request::capture()));
    }

    public function testCustomTokenParam()
    {
        config([
            'postmaster.token' => 'mysupersecrettoken',
            'postmaster.token_parameter' => 'token',
        ]);

        $auth = resolve(TokenAuth::class);
        $request = Request::capture();
        $this->assertFalse($auth($request));

        $request->offsetSet('auth', 'mysupersecrettoken');
        $this->assertFalse($auth($request));

        $request->offsetSet('token', 'mysupersecrettoken');
        $this->assertTrue($auth($request));
    }
}