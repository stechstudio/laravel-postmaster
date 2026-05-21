<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Request;

/**
 * The inbox: a filterable, cross-tenant list of recorded messages, and the
 * per-message detail view with its delivery timeline and stored content.
 */
class MessageController extends Controller
{
    public function index( Request $request )
    {
        $query = $this->messageQuery()->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($provider = $request->query('provider')) {
            $query->where('provider', $provider);
        }

        $tenant = $request->query('tenant');

        if ($tenant !== null && $tenant !== '') {
            $query->where($this->tenantColumn(), $tenant);
        }

        $this->applyContains($query, 'recipient', $request->query('recipient'));
        $this->applyContains($query, 'subject', $request->query('subject'));
        $this->applyDateRange($query, 'created_at', $request->query('from'), $request->query('to'));

        return response()->view('postmaster::messages', [
            'messages'  => $query->paginate(50)->withQueryString(),
            'filters'   => $request->query(),
            'statuses'  => $this->statuses(),
            'providers' => $this->providersInUse(),
            'tenants'   => $this->tenantLabels($this->tenantKeysInUse()),
        ]);
    }

    public function show( $message )
    {
        $record = $this->messageQuery()->findOrFail($message);

        return response()->view('postmaster::message', [
            'message' => $record,
            'events'  => $record->events()->get(),
            'tenants' => $this->tenantLabels([$record->{$this->tenantColumn()}]),
        ]);
    }

    /**
     * Distinct provider names present in the messages table. Providers are
     * stored under their display name ("SendGrid"), so the filter options
     * must come from the data — not the lower-case config keys.
     *
     * @return array
     */
    protected function providersInUse()
    {
        return $this->messageQuery()
            ->whereNotNull('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->all();
    }
}
