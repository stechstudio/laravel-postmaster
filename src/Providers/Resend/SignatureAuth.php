<?php

namespace STS\EmailEvents\Providers\Resend;

use Illuminate\Http\Request;

/**
 * Verifies Resend webhook signatures.
 *
 * Resend signs webhooks with the Svix scheme: an HMAC-SHA256 over
 * "{id}.{timestamp}.{body}" keyed by the decoded "whsec_" signing secret.
 */
class SignatureAuth
{
    /** @var string|null */
    protected $signingSecret;

    public function __construct( $signingSecret )
    {
        $this->signingSecret = $signingSecret;
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    public function __invoke( Request $request )
    {
        if (empty($this->signingSecret)) {
            return false;
        }

        $id        = $request->header('svix-id');
        $timestamp = $request->header('svix-timestamp');
        $signature = $request->header('svix-signature');

        if (empty($id) || empty($timestamp) || empty($signature)) {
            return false;
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $id . '.' . $timestamp . '.' . $request->getContent(),
            $this->secretBytes(),
            true
        ));

        // The header is a space-delimited list of "version,signature" pairs.
        foreach (explode(' ', $signature) as $entry) {
            $parts = explode(',', $entry, 2);

            if (count($parts) === 2 && hash_equals($expected, $parts[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    protected function secretBytes()
    {
        $secret = $this->signingSecret;

        if (str_starts_with($secret, 'whsec_')) {
            $secret = substr($secret, 6);
        }

        return base64_decode($secret);
    }
}
