<?php

namespace STS\EmailEvents\Providers\Ses;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Verifies the signature on an Amazon SNS message (SES delivers events via
 * SNS). The message is signed by AWS; the signing certificate is fetched
 * from a validated amazonaws.com URL and used to verify the signature.
 */
class SignatureAuth
{
    /**
     * Fields included in the signed string, by message type.
     */
    const SIGNED_KEYS = [
        'Notification'             => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation'  => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /**
     * @param Request $request
     *
     * @return bool
     */
    public function __invoke( Request $request )
    {
        $message = json_decode($request->getContent(), true);

        if (! is_array($message) || empty($message['Signature']) || empty($message['SigningCertURL'])) {
            return false;
        }

        if (! static::isAmazonUrl($message['SigningCertURL'])) {
            return false;
        }

        $stringToSign = $this->stringToSign($message);
        $certificate = $this->fetchCertificate($message['SigningCertURL']);

        if ($stringToSign === null || $certificate === null) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certificate);

        if ($publicKey === false) {
            return false;
        }

        $algorithm = ((string) Arr::get($message, 'SignatureVersion')) === '2'
            ? OPENSSL_ALGO_SHA256
            : OPENSSL_ALGO_SHA1;

        return openssl_verify(
            $stringToSign,
            base64_decode($message['Signature']),
            $publicKey,
            $algorithm
        ) === 1;
    }

    /**
     * Whether the URL is an https amazonaws.com SNS host. Critical: an
     * attacker-supplied cert URL would otherwise defeat verification.
     *
     * @param string $url
     *
     * @return bool
     */
    public static function isAmazonUrl( $url )
    {
        $parts = parse_url((string) $url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $parts['host']) === 1;
    }

    /**
     * @param array $message
     *
     * @return string|null
     */
    protected function stringToSign( array $message )
    {
        $keys = self::SIGNED_KEYS[$message['Type'] ?? ''] ?? null;

        if ($keys === null) {
            return null;
        }

        $string = '';

        foreach ($keys as $key) {
            if (array_key_exists($key, $message)) {
                $string .= $key . "\n" . $message[$key] . "\n";
            }
        }

        return $string;
    }

    /**
     * @param string $url
     *
     * @return string|null
     */
    protected function fetchCertificate( $url )
    {
        try {
            $response = Http::get($url);
        } catch (\Throwable $e) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }
}
