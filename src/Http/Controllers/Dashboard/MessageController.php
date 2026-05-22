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

        if ($tag = $request->query('tag')) {
            $query->taggedWith($tag);
        }

        $this->applyContains($query, 'recipient', $request->query('recipient'));
        $this->applyContains($query, 'subject', $request->query('subject'));
        $this->applyDateRange($query, 'created_at', $request->query('from'), $request->query('to'));

        return response()->view('postmaster::messages', [
            'messages'  => $query->paginate(50)->withQueryString(),
            'filters'   => $request->query(),
            'statuses'  => $this->statuses(),
            'providers' => $this->providersInUse(),
            'tags'       => $this->tagsInUse(),
            'tenants'    => $this->tenantLabels($this->tenantKeysInUse()),
            'tenantTerm' => $this->tenantTerm(),
        ]);
    }

    public function show( $message )
    {
        $record = $this->messageQuery()->findOrFail($message);

        return response()->view('postmaster::message', [
            'message'    => $record,
            'events'     => $record->events()->get(),
            'tenants'    => $this->tenantLabels([$record->{$this->tenantColumn()}]),
            'tenantTerm' => $this->tenantTerm(),
            // Remote images are blocked by the preview CSP. The viewer can
            // opt in per view with ?images=1; the bar is offered only when
            // the message actually has a remote image to unblock.
            'showImages'      => request()->boolean('images'),
            'hasRemoteImages' => $this->hasRemoteImages($record->html_body),
        ]);
    }

    /**
     * Whether the HTML contains an <img> with a remote (non-data:) source —
     * i.e. an image the preview CSP would block.
     *
     * @param mixed $html
     *
     * @return bool
     */
    protected function hasRemoteImages( $html )
    {
        return is_string($html)
            && preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']?\s*(?:https?:)?\/\//i', $html) === 1;
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

    /**
     * The distinct tags present across recorded messages, for the filter.
     * Tags live in a JSON array column, so they are flattened in PHP rather
     * than with a database-specific distinct.
     *
     * @return array
     */
    protected function tagsInUse()
    {
        return $this->messageQuery()
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
