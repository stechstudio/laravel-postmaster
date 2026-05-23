<?php

namespace STS\Postmaster\Tests;

use Illuminate\Http\Request;
use STS\Postmaster\Auth\BasicHttpAuth;

class BasicHttpAuthTest extends TestCase
{
    public function testBasicHttpAuth()
    {
        config([
            'postmaster.basic_username' => 'secretusername',
            'postmaster.basic_password' => 'secretpassword',
        ]);

        $auth = resolve(BasicHttpAuth::class);

        $request = Request::capture();
        $this->assertFalse($auth($request));

        $request->headers->set('PHP_AUTH_USER', 'secretusername');
        $request->headers->set('PHP_AUTH_PW', 'secretpassword');

        $this->assertTrue($auth($request));
    }

    public function testRejectsAnUnauthenticatedRequestWhenNoCredsAreConfigured()
    {
        config([
            'postmaster.basic_username' => null,
            'postmaster.basic_password' => null,
        ]);

        // Without this guard, BasicHttpAuth's loose comparison `null == null`
        // would accept a request that supplied no credentials at all.
        $this->assertFalse(resolve(BasicHttpAuth::class)(Request::capture()));
    }

    public function testRejectsWhenOnlyOneCredentialIsConfigured()
    {
        config([
            'postmaster.basic_username' => 'user',
            'postmaster.basic_password' => null,
        ]);

        // A half-configured credential is still unsafe.
        $this->assertFalse(resolve(BasicHttpAuth::class)(Request::capture()));
    }

    public function testRejectsMismatchedCredentials()
    {
        config([
            'postmaster.basic_username' => 'user',
            'postmaster.basic_password' => 'pass',
        ]);

        $auth = resolve(BasicHttpAuth::class);
        $request = Request::capture();
        $request->headers->set('PHP_AUTH_USER', 'user');
        $request->headers->set('PHP_AUTH_PW', 'wrong');

        $this->assertFalse($auth($request));
    }
}