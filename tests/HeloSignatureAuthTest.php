<?php

namespace STS\Postmaster\Tests;

use Illuminate\Http\Request;
use STS\Postmaster\Providers\Helo\SignatureAuth;

class HeloSignatureAuthTest extends TestCase
{
    protected function request(string $header, string $body = '{"hello":"world"}'): Request
    {
        return Request::create('/', 'POST', server: ['HTTP_X_HELO_WEBHOOK_SIGNATURE' => $header], content: $body);
    }

    public function testAuthenticatesRawBodyAndLiteralSigningKey(): void
    {
        $this->freezeTime();
        $time = now()->timestamp;
        $signature = hash_hmac('sha256', $time.'.{"hello":"world"}', 'whsec_test');
        $auth = new SignatureAuth('whsec_test');
        foreach (["t=$time,v1=$signature", "v2=future,t=$time, v1=$signature", "t=$time,v1=abc,v1=$signature"] as $header) {
            $this->assertTrue($auth($this->request($header)));
        }
        $this->assertFalse($auth($this->request("t=$time,v1=$signature", '{"hello": "world"}')));
        $this->assertFalse((new SignatureAuth('wrong'))($this->request("t=$time,v1=$signature")));
        $this->assertFalse((new SignatureAuth(null))($this->request("t=$time,v1=$signature")));
        $this->assertFalse((new SignatureAuth(' , '))($this->request("t=$time,v1=$signature")));
    }

    public function testAcceptsTheKeyOfAnyConfiguredChannelWebhook(): void
    {
        $this->freezeTime();
        $time = now()->timestamp;
        $auth = new SignatureAuth('whsec_channel_a, whsec_channel_b');

        foreach (['whsec_channel_a', 'whsec_channel_b'] as $key) {
            $this->assertTrue($auth($this->request("t=$time,v1=".hash_hmac('sha256', $time.'.{"hello":"world"}', $key))), $key);
        }
        $this->assertFalse($auth($this->request("t=$time,v1=".hash_hmac('sha256', $time.'.{"hello":"world"}', 'whsec_channel_c'))));
    }

    public function testRejectsStaleFutureMalformedAndUnsupportedSignatures(): void
    {
        $this->freezeTime();
        $auth = new SignatureAuth('secret');
        foreach ([-301, 301, -300, 300] as $skew) {
            $time = now()->timestamp + $skew;
            $signature = hash_hmac('sha256', $time.'.{"hello":"world"}', 'secret');
            $this->assertSame(abs($skew) <= 300, $auth($this->request("t=$time,v1=$signature")));
        }
        $time = now()->timestamp;
        foreach (['', 'garbage', "t=$time", 't=yesterday,v1=abc', "t=$time,v2=abc", "t=$time,v1=ABC", "t=$time,v1=abc", "t=$time,t=$time,v1=abc", 't=99999999999999999999999,v1=abc'] as $header) {
            $this->assertFalse($auth($this->request($header)), $header);
        }
    }
}
