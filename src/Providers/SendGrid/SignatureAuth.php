<?php

namespace STS\EmailEvents\Providers\SendGrid;

use Illuminate\Http\Request;

/**
 * Verifies SendGrid's Signed Event Webhook.
 *
 * SendGrid signs each request with ECDSA. The signature covers the request
 * timestamp concatenated with the raw request body, and is verified against
 * the base64 "Verification Key" from SendGrid's webhook settings.
 */
class SignatureAuth
{
    const SIGNATURE_HEADER = 'X-Twilio-Email-Event-Webhook-Signature';
    const TIMESTAMP_HEADER = 'X-Twilio-Email-Event-Webhook-Timestamp';

    /** @var string|null */
    protected $verificationKey;

    public function __construct( $verificationKey )
    {
        $this->verificationKey = $verificationKey;
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    public function __invoke( Request $request )
    {
        if (empty($this->verificationKey)) {
            return false;
        }

        $signature = $request->header(self::SIGNATURE_HEADER);
        $timestamp = $request->header(self::TIMESTAMP_HEADER);

        if (empty($signature) || empty($timestamp)) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($this->publicKeyPem());

        if ($publicKey === false) {
            return false;
        }

        return openssl_verify(
            $timestamp . $request->getContent(),
            base64_decode($signature),
            $publicKey,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    /**
     * SendGrid provides the verification key as a bare base64 DER public key;
     * wrap it into PEM so OpenSSL can load it.
     *
     * @return string
     */
    protected function publicKeyPem()
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($this->verificationKey, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
