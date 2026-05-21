<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Http\Request;

/**
 * The live activity stream — every recorded timeline event, newest first,
 * with a JSON feed the page polls so a superadmin can watch mail flow in
 * real time. Reads email_message_events, so it needs record_events on.
 */
class ActivityController extends Controller
{
    public function index()
    {
        $events = $this->recentEvents();

        return response()->view('postmaster::activity', [
            'events'  => $events->map(fn ($event) => $this->present($event))->values(),
            'lastId'  => $events->max('id') ?? 0,
            'enabled' => (bool) config('postmaster.persistence.record_events', false),
        ]);
    }

    public function feed( Request $request )
    {
        $events = $this->recentEvents((int) $request->query('after', 0));

        return response()->json([
            'events' => $events->map(fn ($event) => $this->present($event))->values(),
            'lastId' => $events->max('id') ?? (int) $request->query('after', 0),
        ]);
    }

    /**
     * The most recent timeline events, optionally only those after an id.
     *
     * @param int $after
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \STS\Postmaster\Models\EmailMessageEvent>
     */
    protected function recentEvents( $after = 0 )
    {
        $query = $this->eventQuery()
            ->with(['emailMessage' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('id')
            ->limit(100);

        if ($after > 0) {
            $query->where('id', '>', $after);
        }

        return $query->get();
    }

    /**
     * Flatten an event for the JSON feed.
     *
     * @param \STS\Postmaster\Models\EmailMessageEvent $event
     *
     * @return array<string, mixed>
     */
    protected function present( $event )
    {
        return [
            'id'        => $event->id,
            'status'    => $event->status,
            'provider'  => $event->provider,
            'recipient' => $event->emailMessage?->getAttribute('recipient'),
            'messageId' => $event->email_message_id,
            'at'        => $event->occurred_at?->format('M j, g:ia'),
        ];
    }
}
