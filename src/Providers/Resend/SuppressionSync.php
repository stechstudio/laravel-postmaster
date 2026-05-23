<?php

namespace STS\Postmaster\Providers\Resend;

use Resend\Resend as ResendClient;
use STS\Postmaster\Contracts\SuppressionSync as Contract;

/**
 * A scaffold for Resend suppression sync. Resend doesn't currently expose
 * a documented "list all suppressed addresses" API — the package's local
 * suppression list is fed entirely by the webhook stream (bounces,
 * complaints) which arrives the same way the other providers do.
 *
 * isAvailable() reports false on purpose, so the sync command logs a hint
 * and moves on. When Resend ships a public suppression API, fill pull()
 * and unsuppress() in; the rest of the architecture is already there.
 *
 * Soft-depends on resend/resend-php (the official SDK).
 */
class SuppressionSync implements Contract
{
    public function __construct( protected array $config )
    {
    }

    public function isAvailable()
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

    public function pull()
    {
        return [];
    }

    public function unsuppress( $address )
    {
        return false;
    }
}
