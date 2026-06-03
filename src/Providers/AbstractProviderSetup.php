<?php

namespace STS\Postmaster\Providers;

use STS\Postmaster\Contracts\ProviderSetup;

/**
 * Shared scaffolding for the per-provider setup profiles: sensible defaults
 * (no transports/hints, generic guidance, sync supported) plus the small
 * helpers concrete profiles lean on. Subclasses override only what differs.
 */
abstract class AbstractProviderSetup implements ProviderSetup
{
    abstract public function name(): string;

    abstract public function label(): string;

    abstract public function askWebhookAuth(): array;

    public function transportNames(): array
    {
        return [];
    }

    public function smtpHints(): array
    {
        return [];
    }

    public function webhookVerb(): string
    {
        return "Point your {$this->label()} webhook at this URL";
    }

    public function supportsSuppressionSync(): bool
    {
        return true;
    }

    public function askSuppressionSync(): array
    {
        return [];
    }

    public function authFailureGuidance(): array
    {
        return [
            'Confirm the webhook auth credential matches what the provider sends (token, basic-auth, or signing secret).',
            'For signature-based providers, check the signing secret and your server clock (skew breaks signatures).',
            $this->configClearReminder(),
        ];
    }

    /**
     * Read a value from this provider's config block, e.g.
     * providerConfig('verification_key').
     */
    protected function providerConfig(string $key, mixed $default = null): mixed
    {
        return config("postmaster.providers.{$this->name()}.{$key}", $default);
    }

    /**
     * "is set" / "is NOT set" for a credential, so guidance can call out a
     * missing value outright rather than just "check it matches".
     */
    protected function isSet(mixed $value): string
    {
        return $value !== null && $value !== '' ? 'is set' : 'is NOT set';
    }

    /**
     * The reminder that cached config keeps stale values after a .env edit —
     * the single most common reason a "fix" to webhook auth appears not to work.
     */
    protected function configClearReminder(): string
    {
        return 'After editing .env, run `php artisan config:clear` — cached config keeps the old values.';
    }
}
