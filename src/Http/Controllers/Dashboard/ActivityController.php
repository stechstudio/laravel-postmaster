<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Request;

/**
 * The activity stream — a filterable, paginated view of every recorded
 * timeline event, newest first. Reads email_message_events, so it needs
 * record_events on. The JSON feed drives the overview's live card.
 */
class ActivityController extends Controller
{
    public function index( Request $request )
    {
        $query = $this->eventQuery()
            ->with(['emailMessage' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($recipient = $request->query('recipient')) {
            $query->whereHas('emailMessage', fn ($q) => $this->applyContains(
                $q->withoutGlobalScopes(), 'recipient', $recipient
            ));
        }

        $tenant = $request->query('tenant');

        if ($tenant !== null && $tenant !== '') {
            $query->whereHas('emailMessage', fn ($q) => $q->withoutGlobalScopes()->where($this->tenantColumn(), $tenant));
        }

        $this->applyDateRange($query, 'occurred_at', $request->query('from'), $request->query('to'));

        return response()->view('postmaster::activity', [
            'events'   => $query->paginate(50)->withQueryString(),
            'filters'  => $request->query(),
            'statuses' => $this->statuses(),
            'tenants'  => $this->tenantLabels($this->tenantKeysInUse()),
            'enabled'  => (bool) config('postmaster.persistence.record_events', false),
        ]);
    }

    public function feed( Request $request )
    {
        $after = (int) $request->query('after', 0);
        $events = $this->recentEvents($after);

        return response()->json([
            'events' => $events->map(fn ($event) => $this->presentEvent($event))->values(),
            'lastId' => $events->max('id') ?? $after,
        ]);
    }
}
