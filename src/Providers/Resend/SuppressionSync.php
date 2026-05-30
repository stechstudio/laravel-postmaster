<?php

namespace STS\Postmaster\Providers\Resend;

use Resend\Client as ResendClient;
use STS\Postmaster\Contracts\SuppressionSync as Contract;

/**
 * Resend has a full REST API (Emails, Contacts, Domains, Webhooks,
 * Broadcasts, ...) but suppressions are a dashboard-only concept there
 * — there's no `/suppressions` resource to list against or delete from.
 * Bounces and complaints are visible in the dashboard and fire as webhook
 * events, but they aren't exposed as a queryable list.
 *
 * In practice that means the package's local suppression table for
 * Resend is fed entirely by the webhook stream, which works the same way
 * it does for the other four providers. isAvailable() reports false here
 * on purpose so the sync command moves on with a clear hint; if Resend
 * ever ships a suppression-list endpoint, fill pull() and unsuppress()
 * in and flip the check — the rest of the architecture is already wired.
 *
 * Soft-depends on resend/resend-php (the official SDK).
 */
class SuppressionSync implements Contract
{
    public function __construct( protected array $config )
    {
    }

    public function isAvailable(): bool
    {
        // The SDK is required; the API key check is here too so that when
        // Resend does ship a suppression list endpoint the only file that
        // changes is pull()/unsuppress().
        if (! class_exists(ResendClient::class) || empty($this->config['api_key'])) {
            return false;
        }

        // Resend's public API doesn't currently expose a suppression list
        // endpoint. Return false until that changes — sync will skip with
        // an informative log line.
        return false;
    }

    public function pull(): iterable
    {
        return [];
    }

    public function unsuppress(string $address): bool
    {
        return false;
    }
}
