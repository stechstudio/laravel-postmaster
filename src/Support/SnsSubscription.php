<?php

namespace STS\Postmaster\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use STS\Postmaster\Providers\Ses\SignatureAuth;

/**
 * Handles the Amazon SNS subscription-confirmation handshake. When SES is
 * first wired to an SNS HTTP(S) endpoint, SNS posts a SubscriptionConfirmation
 * message; the endpoint must visit its SubscribeURL to activate the topic.
 */
class SnsSubscription
{
    /**
     * If the request is an SNS subscription confirmation, return its
     * SubscribeURL — but only when that URL is a genuine amazonaws.com host,
     * so a forged confirmation cannot turn this into an SSRF.
     */
    public static function confirmationUrl(Request $request): ?string
    {
        $message = json_decode($request->getContent(), true);

        if (! is_array($message) || Arr::get($message, 'Type') !== 'SubscriptionConfirmation') {
            return null;
        }

        $url = Arr::get($message, 'SubscribeURL');

        return SignatureAuth::isAmazonUrl($url) ? $url : null;
    }

    public static function confirm(string $url): void
    {
        Http::get($url);
    }
}
