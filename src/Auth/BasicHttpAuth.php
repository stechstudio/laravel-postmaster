<?php

namespace STS\Postmaster\Auth;

use Illuminate\Http\Request;

/**
 * Webhook authorizer that verifies HTTP Basic auth credentials against
 * the configured POSTMASTER_AUTH_USERNAME / POSTMASTER_AUTH_PASSWORD.
 */
class BasicHttpAuth
{
    public function __construct(
        protected ?string $username,
        protected ?string $password,
    ) {
    }

    public function __invoke(Request $request): bool
    {
        // Refuse unconfigured credentials. Without this guard, the loose
        // comparison `null == null` below would accept any unauthenticated
        // request when the env vars aren't set — a fail-open bug.
        if ($this->username === null || $this->username === ''
            || $this->password === null || $this->password === '') {
            return false;
        }

        return hash_equals($this->username, (string) $request->getUser())
            && hash_equals($this->password, (string) $request->getPassword());
    }
}
