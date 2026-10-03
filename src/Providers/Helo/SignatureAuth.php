<?php

namespace STS\Postmaster\Providers\Helo;

use Illuminate\Http\Request;

class SignatureAuth
{
    public function __construct(protected ?string $signingKey)
    {
    }

    public function __invoke(Request $request): bool
    {
        if (empty($this->signingKey)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $request->header('X-Helo-Webhook-Signature')) as $element) {
            [$name, $value] = array_pad(explode('=', trim($element), 2), 2, '');
            $name = trim($name);
            $value = trim($value);

            if ($name === 't') {
                if ($timestamp !== null || ! ctype_digit($value)) {
                    return false;
                }
                $timestamp = $value;
            } elseif ($name === 'v1') {
                if (! preg_match('/^[a-f0-9]+$/D', $value)) {
                    return false;
                }
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        // Helo uses the signing key literally, including any prefix.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $this->signingKey);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
