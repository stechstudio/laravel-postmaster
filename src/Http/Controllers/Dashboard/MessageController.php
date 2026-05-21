<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Request;
use STS\Postmaster\EmailEvent;

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
            $query->where(config('postmaster.persistence.tenant_column', 'tenant_id'), $tenant);
        }

        if ($recipient = $request->query('recipient')) {
            $query->where('recipient', 'like', $recipient.'%');
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        return response()->view('postmaster::messages', [
            'messages'  => $query->paginate(50)->withQueryString(),
            'filters'   => $request->query(),
            'statuses'  => $this->statuses(),
            'providers' => array_keys(config('postmaster.providers', [])),
        ]);
    }

    public function show( $message )
    {
        $record = $this->messageQuery()->findOrFail($message);

        return response()->view('postmaster::message', [
            'message' => $record,
            'events'  => $record->events()->get(),
        ]);
    }

    /**
     * The lifecycle statuses, for the filter dropdown.
     *
     * @return array<int, string>
     */
    protected function statuses()
    {
        return [
            EmailEvent::EVENT_SENT,
            EmailEvent::EMAIL_ACCEPTED,
            EmailEvent::EVENT_DEFERRED,
            EmailEvent::EVENT_DELIVERED,
            EmailEvent::EVENT_BOUNCED,
            EmailEvent::EVENT_DROPPED,
            EmailEvent::EVENT_COMPLAINED,
            EmailEvent::EVENT_OPENED,
            EmailEvent::EVENT_CLICKED,
        ];
    }
}
