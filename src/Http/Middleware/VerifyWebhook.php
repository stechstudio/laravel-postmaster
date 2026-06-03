<?php

namespace STS\Postmaster\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use STS\Postmaster\Listeners\RelayVerificationEvent;
use STS\Postmaster\Postmaster;
use STS\Postmaster\Exceptions\UnauthorizedException;

/**
 * Verifies an inbound provider webhook using that provider's configured
 * authorizer (token, basic auth, signature, user-agent, ...). Runs before
 * WebhookController so the controller only ever sees trusted requests.
 */
class VerifyWebhook
{
    public function __construct(protected Postmaster $events)
    {
    }

    public function handle(Request $request, Closure $next): mixed
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

            // If a postmaster:verify run is watching, signal that a webhook
            // really did arrive but was rejected — so the command can call out
            // the auth config instead of reporting a generic "nothing arrived".
            // Gated on a verify being in progress so steady-state production
            // traffic never writes this key.
            if (Cache::has(RelayVerificationEvent::WATCHING_KEY)) {
                Cache::put(RelayVerificationEvent::AUTH_FAILED_KEY, [
                    'provider' => $providerName,
                    'at'       => now()->format(DATE_ATOM),
                ], now()->addMinutes(10));
            }

            throw new UnauthorizedException($request);
        }

        return $next($request);
    }
}
