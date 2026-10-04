<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Response;
use STS\Postmaster\Facades\Postmaster;

/**
 * A read-only view of how Postmaster and the app's mail are set up, with
 * any settings that need attention called out first.
 */
class ConfigurationController extends Controller
{
    public function __invoke(): Response
    {
        return response()->view('postmaster::configuration', [
            'report'      => Postmaster::configuration(),
            // Postmaster's own "sent" rows have no provider; every webhook event does.
            'lastWebhook' => $this->activityQuery()->whereNotNull('provider')->latest('id')->first(),
        ]);
    }
}
