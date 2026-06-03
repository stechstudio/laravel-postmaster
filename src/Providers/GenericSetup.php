<?php

namespace STS\Postmaster\Providers;

use Illuminate\Support\Str;

/**
 * Fallback profile for a provider that has no dedicated Setup class — e.g. one
 * registered at runtime via Postmaster::extend(). It carries no provider-specific
 * knowledge, so detection and guidance degrade gracefully to the generic
 * defaults rather than erroring.
 */
class GenericSetup extends AbstractProviderSetup
{
    public function __construct(protected string $providerName)
    {
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function label(): string
    {
        return Str::headline($this->providerName);
    }

    public function askWebhookAuth(): array
    {
        return [];
    }
}
