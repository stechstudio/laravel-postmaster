<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use STS\Postmaster\Models\EmailMessage;

/**
 * The activity stream — a filterable, paginated view of every recorded
 * timeline entry, newest first. Reads email_activity, so it needs
 * record_events on. The JSON feed drives the overview's live card.
 */
class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $this->activityQuery()
            ->with([
                'emailMessage' => fn ($q) => $q->withoutGlobalScopes(),
                'emailAddress',
            ])
            ->orderByDesc('id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            // "To" search matches the message recipient (lifecycle entries) OR
            // the address itself (address-only entries) — case-insensitive.
            ->when($request->filled('to'), fn ($q) => $q->where(fn ($sub) => $sub
                ->whereHas('emailMessage', fn ($mq) => $this->applyContains(
                    $mq->withoutGlobalScopes(), 'to_address', $request->query('to')
                ))
                ->orWhereHas('emailAddress', fn ($aq) => $this->applyContains(
                    $aq, 'address', $request->query('to')
                ))))
            ->when($request->filled('tenant'), fn ($q) => $q->whereHas(
                'emailMessage',
                fn ($mq) => $mq->withoutGlobalScopes()->where(EmailMessage::tenantColumn(), $request->query('tenant'))
            ));

        $this->applyDateRange($query, 'occurred_at', $request->query('date_from'), $request->query('date_to'));

        return response()->view('postmaster::activity', [
            'entries'    => $query->paginate(50)->withQueryString(),
            'filters'    => $request->query(),
            'statuses'   => $this->statuses(),
            'tenants'    => $this->tenantLabels($this->tenantKeysInUse()),
            'tenantTerm' => $this->tenantTerm(),
            'enabled'    => (bool) config('postmaster.persistence.record_events', false),
        ]);
    }

    public function feed(Request $request): JsonResponse
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
