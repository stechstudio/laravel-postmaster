<?php

namespace STS\Postmaster\Http\Middleware;

use Closure;
use STS\Postmaster\Postmaster;

/**
 * Guards the dashboard with the gate registered via Postmaster::auth().
 *
 * The dashboard is a deliberately cross-tenant view of all email activity, so
 * this is the one place tenant isolation is bypassed by design — the gate is
 * what keeps it safe. With no gate registered, access is allowed only locally.
 */
class AuthorizeDashboard
{
    /**
     * @param \Illuminate\Http\Request $request
     * @param Closure                  $next
     *
     * @return mixed
     */
    public function handle( $request, Closure $next )
    {
        abort_unless(app(Postmaster::class)->authorize($request), 403);

        return $next($request);
    }
}
