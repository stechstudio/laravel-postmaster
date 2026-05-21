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
            'events'  => $events->map(fn ($event) => $this->presentEvent($event))->values(),
            'lastId'  => $events->max('id') ?? 0,
            'enabled' => (bool) config('postmaster.persistence.record_events', false),
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
