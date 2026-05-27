<?php

namespace STS\Postmaster\Tests;

use Illuminate\Http\Request;
use STS\Postmaster\Providers\Mailgun\SignatureAuth;

/**
 * Regression for the Mailgun replay-prevention window.
 *
 * The previous 15-second window rejected normal webhooks once any latency
 * piled up (a tunnel, a slow handler, a brief network hiccup). Mailgun's
 * own token validity is 15 minutes; 5 minutes is the practical middle
 * that prevents replay without producing false negatives.
 */
class MailgunSignatureWindowTest extends TestCase
{
    public function testTimestampWithinFiveMinutesPasses()
    {
        $signingKey = 'k';
        $timestamp  = time() - 200; // 3m20s ago — fine through any tunnel
        $token      = 'tok';
        $signature  = hash_hmac('sha256', $timestamp . $token, $signingKey);

        $request = Request::create('/', 'POST', [
            'signature' => compact('timestamp', 'token', 'signature'),
        ]);

        $this->assertTrue((new SignatureAuth($signingKey))($request));
    }

    public function testTimestampOlderThanFiveMinutesIsRejected()
    {
        $signingKey = 'k';
        $timestamp  = time() - 600; // 10 minutes ago — past the window
        $token      = 'tok';
        $signature  = hash_hmac('sha256', $timestamp . $token, $signingKey);

        $request = Request::create('/', 'POST', [
            'signature' => compact('timestamp', 'token', 'signature'),
        ]);

        $this->assertFalse((new SignatureAuth($signingKey))($request));
    }

    public function testTimestampInTheFutureBeyondTheWindowIsRejected()
    {
        // abs() in the comparison means future timestamps also fail. A
        // legitimate webhook never lands here, but a maliciously crafted
        // one would.
        $signingKey = 'k';
        $timestamp  = time() + 600;
        $token      = 'tok';
        $signature  = hash_hmac('sha256', $timestamp . $token, $signingKey);

        $request = Request::create('/', 'POST', [
            'signature' => compact('timestamp', 'token', 'signature'),
        ]);

        $this->assertFalse((new SignatureAuth($signingKey))($request));
    }

    public function testTimestampSlightlyInTheFutureIsAcceptedForClockSkew()
    {
        // Small skew (a few seconds ahead) is normal in distributed
        // systems. The window has to accept it.
        $signingKey = 'k';
        $timestamp  = time() + 10;
        $token      = 'tok';
        $signature  = hash_hmac('sha256', $timestamp . $token, $signingKey);

        $request = Request::create('/', 'POST', [
            'signature' => compact('timestamp', 'token', 'signature'),
        ]);

        $this->assertTrue((new SignatureAuth($signingKey))($request));
    }
}
