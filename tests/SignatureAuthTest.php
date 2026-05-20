<?php

namespace STS\EmailEvents\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use STS\EmailEvents\Providers\Mailgun\SignatureAuth as MailgunSignatureAuth;
use STS\EmailEvents\Providers\Resend\SignatureAuth as ResendSignatureAuth;
use STS\EmailEvents\Providers\Ses\SignatureAuth as SesSignatureAuth;
use STS\EmailEvents\Providers\SendGrid\SignatureAuth as SendGridSignatureAuth;

class SignatureAuthTest extends TestCase
{
    protected function request(string $content, array $headers = []): Request
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [], $content);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    // --- SendGrid (ECDSA) ---------------------------------------------------

    public function testSendGridSignatureAuth()
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        $verificationKey = str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
            '',
            openssl_pkey_get_details($key)['key']
        );

        $timestamp = '1609459200';
        $body = '{"event":"delivered"}';
        openssl_sign($timestamp . $body, $signature, $key, OPENSSL_ALGO_SHA256);

        $auth = new SendGridSignatureAuth($verificationKey);

        $valid = $this->request($body, [
            SendGridSignatureAuth::SIGNATURE_HEADER => base64_encode($signature),
            SendGridSignatureAuth::TIMESTAMP_HEADER => $timestamp,
        ]);
        $this->assertTrue($auth($valid));

        $tampered = $this->request('{"event":"opened"}', [
            SendGridSignatureAuth::SIGNATURE_HEADER => base64_encode($signature),
            SendGridSignatureAuth::TIMESTAMP_HEADER => $timestamp,
        ]);
        $this->assertFalse($auth($tampered));

        $this->assertFalse($auth($this->request($body)));
    }

    public function testSendGridSignatureAuthFailsWithoutAVerificationKey()
    {
        $auth = new SendGridSignatureAuth(null);

        $this->assertFalse($auth($this->request('{}', [
            SendGridSignatureAuth::SIGNATURE_HEADER => 'x',
            SendGridSignatureAuth::TIMESTAMP_HEADER => '1',
        ])));
    }

    // --- Resend (Svix HMAC) -------------------------------------------------

    public function testResendSignatureAuth()
    {
        $secretBytes = random_bytes(24);
        $signingSecret = 'whsec_' . base64_encode($secretBytes);

        $id = 'msg_abc';
        $timestamp = '1609459200';
        $body = '{"type":"email.delivered"}';
        $signature = base64_encode(hash_hmac('sha256', "$id.$timestamp.$body", $secretBytes, true));

        $auth = new ResendSignatureAuth($signingSecret);

        $valid = $this->request($body, [
            'svix-id'        => $id,
            'svix-timestamp' => $timestamp,
            'svix-signature' => "v1,$signature",
        ]);
        $this->assertTrue($auth($valid));

        $tampered = $this->request('{"type":"email.opened"}', [
            'svix-id'        => $id,
            'svix-timestamp' => $timestamp,
            'svix-signature' => "v1,$signature",
        ]);
        $this->assertFalse($auth($tampered));

        $this->assertFalse($auth($this->request($body)));
    }

    // --- Amazon SES (SNS) ---------------------------------------------------

    public function testSesSignatureAuth()
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key);
        $x509 = openssl_csr_sign($csr, null, $key, 365);
        openssl_x509_export($x509, $certificate);

        $message = [
            'Type'             => 'Notification',
            'MessageId'        => 'sns-1',
            'Message'          => '{"eventType":"Delivery"}',
            'Timestamp'        => '2021-01-01T00:00:00.000Z',
            'TopicArn'         => 'arn:aws:sns:us-east-1:123456789012:ses',
            'SignatureVersion' => '1',
            'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/cert.pem',
        ];

        $stringToSign = '';
        foreach (['Message', 'MessageId', 'Timestamp', 'TopicArn', 'Type'] as $field) {
            $stringToSign .= $field . "\n" . $message[$field] . "\n";
        }
        openssl_sign($stringToSign, $signature, $key, OPENSSL_ALGO_SHA1);
        $message['Signature'] = base64_encode($signature);

        Http::fake([
            'sns.us-east-1.amazonaws.com/*' => Http::response($certificate),
        ]);

        $auth = new SesSignatureAuth();

        $this->assertTrue($auth($this->request(json_encode($message))));

        $tampered = $message;
        $tampered['Message'] = '{"eventType":"Bounce"}';
        $this->assertFalse($auth($this->request(json_encode($tampered))));
    }

    public function testSesSignatureAuthRejectsNonAmazonCertUrl()
    {
        $auth = new SesSignatureAuth();

        $message = [
            'Type'           => 'Notification',
            'Signature'      => 'whatever',
            'SigningCertURL' => 'https://evil.example.com/cert.pem',
        ];

        $this->assertFalse($auth($this->request(json_encode($message))));
    }

    public function testIsAmazonUrl()
    {
        $this->assertTrue(SesSignatureAuth::isAmazonUrl('https://sns.us-east-1.amazonaws.com/cert.pem'));
        $this->assertFalse(SesSignatureAuth::isAmazonUrl('http://sns.us-east-1.amazonaws.com/cert.pem'));
        $this->assertFalse(SesSignatureAuth::isAmazonUrl('https://evil.com/cert.pem'));
        $this->assertFalse(SesSignatureAuth::isAmazonUrl('https://sns.us-east-1.amazonaws.com.evil.com/x'));
    }

    // --- Mailgun (HMAC) -----------------------------------------------------

    public function testMailgunSignatureAuth()
    {
        $signingKey = 'mailgun-signing-key';
        $timestamp = (string) time();
        $token = 'mailgun-token';
        $signature = hash_hmac('sha256', $timestamp . $token, $signingKey);

        $auth = new MailgunSignatureAuth($signingKey);

        $valid = Request::create('/webhook', 'POST', [
            'signature' => compact('timestamp', 'token', 'signature'),
        ]);
        $this->assertTrue($auth($valid));

        $invalid = Request::create('/webhook', 'POST', [
            'signature' => [
                'timestamp' => $timestamp,
                'token'     => $token,
                'signature' => 'wrong',
            ],
        ]);
        $this->assertFalse($auth($invalid));
    }
}
