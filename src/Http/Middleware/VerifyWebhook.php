<?php

namespace STS\Postmaster\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use STS\Postmaster\Postmaster;
use STS\Postmaster\Exceptions\UnauthorizedException;

/**
 * Verifies an inbound provider webhook using that provider's configured
 * authorizer (token, basic auth, signature, user-agent, ...). Runs before
 * WebhookController so the controller only ever sees trusted requests.
 */
class VerifyWebhook
{
    public function __construct( protected Postmaster $events )
    {
    }

    /**
     * @param Request $request
     * @param Closure $next
     *
     * @return mixed
     */
    public function handle( Request $request, Closure $next )
    {
        $provider = $this->events->provider($request->route('provider'));

        if (! $provider->passesAuthorization($request)) {
            throw new UnauthorizedException($request);
        }

        return $next($request);
    }
}
