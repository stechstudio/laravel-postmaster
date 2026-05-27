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
        $providerName = $request->route('provider');
        $provider = $this->events->provider($providerName);

        if (! $provider->passesAuthorization($request)) {
            // Logged at info so it's visible during setup / debugging without
            // dominating logs in steady-state. A failing webhook in
            // production usually means a misconfigured credential or a clock
            // skew, both of which are silent failures otherwise.
            logger()->info("Postmaster: webhook auth failed for provider [{$providerName}]", [
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            throw new UnauthorizedException($request);
        }

        return $next($request);
    }
}
