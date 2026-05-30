<?php

namespace STS\Postmaster\Auth;

use Illuminate\Http\Request;

/**
 * Webhook authorizer that verifies a URL token against the configured
 * POSTMASTER_AUTH_TOKEN. The token is read from the request input field
 * named by $parameter (defaults to "auth").
 */
class TokenAuth
{
    public function __construct(
        protected ?string $token,
        protected string $parameter = 'auth',
    ) {
    }

    public function __invoke(Request $request): bool
    {
        // Refuse an unconfigured token. Without this guard, the loose
        // comparison `null == null` below would accept any unauthenticated
        // request when POSTMASTER_AUTH_TOKEN isn't set — a fail-open bug.
        if ($this->token === null || $this->token === '') {
            return false;
        }

        return hash_equals($this->token, (string) $request->input($this->parameter));
    }
}
