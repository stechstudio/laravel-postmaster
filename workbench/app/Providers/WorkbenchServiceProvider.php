<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use STS\Postmaster\Facades\Postmaster;

/**
 * Wires the package up for local preview via `composer serve`. Turns on
 * persistence and the dashboard, and opens the dashboard gate so it can be
 * browsed without an authenticated superadmin user.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Only shape the environment for `composer serve`, never for the
        // test suite — tests configure themselves.
        if ($this->app->runningUnitTests()) {
            return;
        }

        config([
            'app.key' => 'base64:'.base64_encode('postmaster-workbench-local-key!!'),
            'postmaster.persistence.enabled'        => true,
            'postmaster.persistence.record_events'  => true,
            'postmaster.persistence.track_addresses' => true,
            'postmaster.dashboard.enabled'          => true,
        ]);
    }

    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        // Open the gate for local preview. A real app would check the user.
        Postmaster::auth(fn ($request) => true);
    }
}
