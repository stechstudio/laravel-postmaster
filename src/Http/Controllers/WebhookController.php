<?php

namespace STS\EmailEvents\Http\Controllers;

use Illuminate\Http\Request;
use STS\EmailEvents\EmailEvents;

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
        $this->events->provider($provider)
            ->adapt($request->all())
            ->dispatch();

        return response('', 200);
    }
}
