<?php

namespace STS\EmailEvents\Http\Controllers;

use Illuminate\Http\Request;
use STS\EmailEvents\EmailEvents;
use STS\EmailEvents\Support\SnsSubscription;

/**
 * Receives a provider webhook, adapts the payload into EmailEvents and
 * dispatches them. Authorization is handled upstream by the VerifyWebhook
 * middleware, so by the time a request reaches here it is already trusted.
 */
class WebhookController
{
    public function __construct( protected EmailEvents $events )
    {
    }

    /**
     * @param Request $request
     * @param string  $provider
     *
     * @return \Illuminate\Http\Response
     */
    public function __invoke( Request $request, string $provider )
    {
        // SES is delivered via SNS, which first performs a subscription
        // handshake. The signature was already verified by the middleware.
        if ($url = SnsSubscription::confirmationUrl($request)) {
            SnsSubscription::confirm($url);

            return response('', 200);
        }

        $this->events->provider($provider)
            ->adapt($this->payload($request))
            ->dispatch();

        return response('', 200);
    }

    /**
     * The decoded webhook payload. Falls back to decoding the raw body so
     * that providers posting as text/plain (e.g. Amazon SNS) still work.
     *
     * @param Request $request
     *
     * @return array
     */
    protected function payload( Request $request )
    {
        $payload = $request->all();

        if (! empty($payload)) {
            return $payload;
        }

        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
