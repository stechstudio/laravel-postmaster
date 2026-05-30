<?php

namespace STS\Postmaster\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use STS\Postmaster\Jobs\ProcessWebhook;
use STS\Postmaster\Postmaster;
use STS\Postmaster\Support\SnsSubscription;

/**
 * Receives a provider webhook, adapts the payload into Postmaster and
 * dispatches them. Authorization is handled upstream by the VerifyWebhook
 * middleware, so by the time a request reaches here it is already trusted.
 */
class WebhookController
{
    public function __construct(protected Postmaster $events)
    {
    }

    public function __invoke(Request $request, string $provider): Response
    {
        // SES is delivered via SNS, which first performs a subscription
        // handshake. The signature was already verified by the middleware.
        // This always runs inline — the confirmation URL has to be hit
        // before the response returns to complete the subscription.
        if ($url = SnsSubscription::confirmationUrl($request)) {
            SnsSubscription::confirm($url);

            return response('', 200);
        }

        $payload = $this->payload($request);

        if (config('postmaster.queue_webhooks')) {
            ProcessWebhook::dispatch($provider, $payload);

            // 202 Accepted — the request is queued for processing; events
            // will dispatch from the worker, not before this response.
            return response('', 202);
        }

        $this->events->provider($provider)
            ->adapt($payload)
            ->dispatch();

        return response('', 200);
    }

    /**
     * The decoded webhook payload. Read from the request body only — never
     * the query string, so an "?auth=" token does not leak into the payload.
     * Falls back to decoding the raw body for providers posting as text/plain
     * (e.g. Amazon SNS).
     *
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        if ($request->isJson()) {
            return (array) $request->json()->all();
        }

        $decoded = json_decode($request->getContent(), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return $request->request->all();
    }
}
