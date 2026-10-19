<?php

namespace STS\Postmaster\Tests;

use Illuminate\Http\Request;
use STS\Postmaster\Providers\PostShiba\SignatureAuth;

class PostShibaSignatureAuthTest extends TestCase
{
    protected const BODY = '[{"event":"delivered","email":"you@example.com","sg_event_id":"capsule-44"}]';

    public static function sign(string $body, string $secret, int|string $timestamp): string
    {
        return 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    protected function request(?string $signature, int|string|null $timestamp, string $body = self::BODY): Request
    {
        $server = array_filter([
            'HTTP_X_CAPSULE_SIGNATURE' => $signature,
            'HTTP_X_CAPSULE_TIMESTAMP' => $timestamp === null ? null : (string) $timestamp,
        ], fn ($value) => $value !== null);

        return Request::create('/', 'POST', server: $server, content: $body);
    }

    public function testAuthenticatesTheTimestampAndRawBody(): void
    {
        $auth = new SignatureAuth('secret');
        $now = time();

        $this->assertTrue($auth($this->request(self::sign(self::BODY, 'secret', $now), $now)));
        $this->assertFalse($auth($this->request(self::sign(self::BODY, 'wrong', $now), $now)));
        $this->assertFalse($auth($this->request(self::sign(self::BODY, 'secret', $now - 1), $now)));
        $this->assertFalse($auth($this->request(self::sign(self::BODY, 'secret', $now), $now, str_replace(':', ': ', self::BODY))));
    }

    public function testRequiresTheSha256PrefixAndLowercaseHex(): void
    {
        $auth = new SignatureAuth('secret');
        $now = time();
        $hex = hash_hmac('sha256', "{$now}.".self::BODY, 'secret');

        $this->assertFalse($auth($this->request($hex, $now)));
        $this->assertFalse($auth($this->request('sha256='.strtoupper($hex), $now)));
        $this->assertFalse($auth($this->request('', $now)));
        $this->assertFalse($auth($this->request(null, $now)));
    }

    public function testRejectsStaleFutureAndMissingTimestamps(): void
    {
        $auth = new SignatureAuth('secret');

        foreach ([time() - 301, time() + 301] as $timestamp) {
            $this->assertFalse($auth($this->request(self::sign(self::BODY, 'secret', $timestamp), $timestamp)));
        }

        $this->assertTrue($auth($this->request(self::sign(self::BODY, 'secret', time() - 299), time() - 299)));
        $this->assertFalse($auth($this->request(self::sign(self::BODY, 'secret', ''), null)));
        $this->assertFalse($auth($this->request(self::sign(self::BODY, 'secret', 'abc'), 'abc')));
    }

    public function testAcceptsTheSecretOfAnyConfiguredEndpoint(): void
    {
        $auth = new SignatureAuth('secret-a, secret-b');
        $now = time();

        $this->assertTrue($auth($this->request(self::sign(self::BODY, 'secret-a', $now), $now)));
        $this->assertTrue($auth($this->request(self::sign(self::BODY, 'secret-b', $now), $now)));
        $this->assertFalse($auth($this->request(self::sign(self::BODY, 'secret-c', $now), $now)));
    }

    public function testRejectsEverythingWithoutASecret(): void
    {
        $now = time();

        foreach ([null, '', ' , '] as $secrets) {
            $this->assertFalse((new SignatureAuth($secrets))($this->request(self::sign(self::BODY, '', $now), $now)));
        }
    }
}
