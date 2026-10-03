<?php

namespace STS\Postmaster\Providers\Helo;

use Illuminate\Http\Request;

class SignatureAuth
{
    /** @var list<string> */
    protected array $signingKeys;

    /**
     * Each Helo webhook belongs to one channel and has its own signing key,
     * so an app with several channels passes several keys, comma-separated.
     */
    public function __construct(?string $signingKeys)
    {
        $this->signingKeys = array_values(array_filter(array_map('trim', explode(',', (string) $signingKeys))));
    }

    public function __invoke(Request $request): bool
    {
        if ($this->signingKeys === []) {
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

        foreach ($this->signingKeys as $signingKey) {
            // Helo uses the signing key literally, including any prefix.
            $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $signingKey);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }
}
