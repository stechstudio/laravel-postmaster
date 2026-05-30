<?php

namespace STS\Postmaster\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use STS\Postmaster\Contracts\Adapter;

/**
 * Webhook authorizer that matches the request's User-Agent against the
 * adapter's expected pattern — useful for providers that send a stable
 * identifying UA on every webhook.
 */
class UserAgentAuth
{
    /**
     * @param class-string<Adapter> $adapterClass
     */
    public function __invoke(Request $request, string $adapterClass): bool
    {
        return Str::is($adapterClass::getUserAgent(), $request->userAgent());
    }
}
