<?php

namespace STS\Postmaster\Tests;

use Illuminate\Http\Request;
use STS\Postmaster\Providers\MailerSend\SignatureAuth;

class MailerSendSignatureAuthTest extends TestCase
{
    protected const BODY = '{"type":"activity.delivered","data":{"message_id":"abc"}}';

    protected const PING = '{"type":"webhook.test","message":"This is a ping test message","created_at":"2026-03-27T07:24:20.577080Z"}';

    protected function request(?string $signature, string $body = self::BODY, string $header = 'Signature'): Request
    {
        $server = $signature === null ? [] : ['HTTP_'.strtoupper(str_replace('-', '_', $header)) => $signature];

        return Request::create('/', 'POST', server: $server, content: $body);
    }

    public function testAuthenticatesTheRawBodyWithTheSigningSecret(): void
    {
        $auth = new SignatureAuth('secret');

        $this->assertTrue($auth($this->request(hash_hmac('sha256', self::BODY, 'secret'))));
        $this->assertFalse($auth($this->request(hash_hmac('sha256', self::BODY, 'wrong'))));
        $this->assertFalse($auth($this->request(hash_hmac('sha256', self::BODY, 'secret'), str_replace(':', ': ', self::BODY))));
        $this->assertFalse($auth($this->request(strtoupper(hash_hmac('sha256', self::BODY, 'secret')))));
        $this->assertFalse($auth($this->request('')));
        $this->assertFalse($auth($this->request(null)));
    }

    public function testAcceptsTheSecretOfAnyConfiguredWebhook(): void
    {
        // Each MailerSend webhook, one per domain, has its own secret.
        $auth = new SignatureAuth('secret-a, secret-b');

        $this->assertTrue($auth($this->request(hash_hmac('sha256', self::BODY, 'secret-a'))));
        $this->assertTrue($auth($this->request(hash_hmac('sha256', self::BODY, 'secret-b'))));
        $this->assertFalse($auth($this->request(hash_hmac('sha256', self::BODY, 'secret-c'))));
    }

    public function testRejectsEverythingWithoutASecret(): void
    {
        foreach ([null, '', ' , '] as $secrets) {
            $this->assertFalse((new SignatureAuth($secrets))($this->request(hash_hmac('sha256', self::BODY, ''))));
        }
    }

    public function testAcceptsThePingSignedWithMailerSendsPublicTestSecret(): void
    {
        // MailerSend pings the URL before the webhook, and its secret, exist.
        // The test secret is public, so it must not authenticate real events.
        $test = SignatureAuth::TEST_SECRET;

        $this->assertTrue((new SignatureAuth(null))($this->request(hash_hmac('sha256', self::PING, $test), self::PING)));
        $this->assertTrue((new SignatureAuth('secret'))($this->request(hash_hmac('sha256', self::PING, $test), self::PING)));
        $this->assertFalse((new SignatureAuth('secret'))($this->request(hash_hmac('sha256', self::BODY, $test))));
        $this->assertFalse((new SignatureAuth(null))($this->request(hash_hmac('sha256', self::PING, 'other'), self::PING)));
        $this->assertFalse((new SignatureAuth(null))($this->request(hash_hmac('sha256', '5', $test), '5')));
    }

    public function testReadsTheHeaderNameOneSdkDocuments(): void
    {
        // MailerSend's Node SDK reads X-MailerSend-Signature; every other source says Signature.
        $signature = hash_hmac('sha256', self::BODY, 'secret');

        $this->assertTrue((new SignatureAuth('secret'))($this->request($signature, header: 'X-MailerSend-Signature')));
    }
}
