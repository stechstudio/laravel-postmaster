<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Request;

/**
 * The activity stream — a filterable, paginated view of every recorded
 * timeline entry, newest first. Reads email_activity, so it needs
 * record_events on. The JSON feed drives the overview's live card.
 */
class ActivityController extends Controller
{
    public function index( Request $request )
    {
        $query = $this->activityQuery()
            ->with([
                'emailMessage' => fn ($q) => $q->withoutGlobalScopes(),
                'emailAddress',
            ])
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // "To" search matches the message recipient (lifecycle entries) OR
        // the address itself (address-only entries) — case-insensitive.
        if ($to = $request->query('to')) {
            $query->where(function ($q) use ($to) {
                $q->whereHas('emailMessage', fn ($mq) => $this->applyContains(
                    $mq->withoutGlobalScopes(), 'to_address', $to
                ))->orWhereHas('emailAddress', fn ($aq) => $this->applyContains(
                    $aq, 'address', $to
                ));
            });
        }

        $tenant = $request->query('tenant');

        if ($tenant !== null && $tenant !== '') {
            $query->whereHas('emailMessage', fn ($q) => $q->withoutGlobalScopes()->where($this->tenantColumn(), $tenant));
        }

        $this->applyDateRange($query, 'occurred_at', $request->query('from'), $request->query('to'));

        return response()->view('postmaster::activity', [
            'entries'    => $query->paginate(50)->withQueryString(),
            'filters'    => $request->query(),
            'statuses'   => $this->statuses(),
            'tenants'    => $this->tenantLabels($this->tenantKeysInUse()),
            'tenantTerm' => $this->tenantTerm(),
            'enabled'    => (bool) config('postmaster.persistence.record_events', false),
        ]);
    }

    public function feed( Request $request )
    {
        $after   = (int) $request->query('after', 0);
        $entries = $this->recentActivity($after);

        // The JSON key stays `events` because the consuming JS treats this
        // as a real-time event stream rather than as paginated rows.
        return response()->json([
            'events' => $entries->map(fn ($entry) => $this->presentActivity($entry))->values(),
            'lastId' => $entries->max('id') ?? $after,
        ]);
    }
}
