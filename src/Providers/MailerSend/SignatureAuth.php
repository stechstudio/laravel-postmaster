<?php

namespace STS\Postmaster\Providers\MailerSend;

use Illuminate\Http\Request;

/**
 * MailerSend signs the raw body with HMAC-SHA256 and sends the hex digest in
 * the Signature header. The signature has no timestamp, so there is no
 * replay window to check.
 */
class SignatureAuth
{
    /**
     * MailerSend signs the ping it sends while you create a webhook with this
     * published secret, before the webhook's own secret exists.
     */
    public const TEST_SECRET = 'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G';

    /** @var list<string> */
    protected array $signingSecrets;

    /**
     * Each MailerSend webhook belongs to one domain and has its own secret,
     * so an app with several domains passes several, comma-separated.
     */
    public function __construct(?string $signingSecrets)
    {
        $this->signingSecrets = array_values(array_filter(array_map('trim', explode(',', (string) $signingSecrets))));
    }

    public function __invoke(Request $request): bool
    {
        $signature = (string) ($request->header('Signature') ?? $request->header('X-MailerSend-Signature'));

        if (! preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            return false;
        }

        foreach ($this->secretsFor($request) as $secret) {
            if (hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The test secret is public, so it only authenticates the ping, which
     * the adapter then ignores.
     *
     * @return list<string>
     */
    protected function secretsFor(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        $isPing = is_array($payload) && ($payload['type'] ?? null) === 'webhook.test';

        return $isPing ? [...$this->signingSecrets, self::TEST_SECRET] : $this->signingSecrets;
    }
}
