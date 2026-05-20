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
}