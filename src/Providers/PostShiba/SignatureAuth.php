<?php

namespace STS\Postmaster\Providers\PostShiba;

use Illuminate\Http\Request;

/**
 * PostShiba signs "{timestamp}.{raw body}" with HMAC-SHA256 and sends
 * "sha256=<hex>" in X-Capsule-Signature. Every attempt, retries included, is
 * signed with a fresh X-Capsule-Timestamp, so stale timestamps are rejected.
 */
class SignatureAuth
{
    /** Seconds a signature stays valid, the same window Mailgun gets. */
    protected const TOLERANCE = 300;

    /** @var list<string> */
    protected array $signingSecrets;

    /** Each webhook endpoint has its own secret; separate several with commas. */
    public function __construct(?string $signingSecrets)
    {
        $this->signingSecrets = array_values(array_filter(array_map('trim', explode(',', (string) $signingSecrets))));
    }

    public function __invoke(Request $request): bool
    {
        $timestamp = (string) $request->header('X-Capsule-Timestamp');
        $signature = (string) $request->header('X-Capsule-Signature');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        if (! preg_match('/^sha256=([a-f0-9]{64})$/D', $signature, $matches)) {
            return false;
        }

        foreach ($this->signingSecrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $matches[1])) {
                return true;
            }
        }

        return false;
    }
}
