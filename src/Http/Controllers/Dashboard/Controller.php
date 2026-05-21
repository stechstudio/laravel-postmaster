<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Database\Eloquent\Builder;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailMessageEvent;

/**
 * Base for the dashboard controllers. Every query is built without global
 * scopes — the dashboard is a deliberately cross-tenant view, so a tenant
 * scope on a swapped-in model must not hide rows here.
 */
abstract class Controller
{
    /**
     * @return Builder<EmailMessage>
     */
    protected function messageQuery()
    {
        $class = config('postmaster.persistence.model', EmailMessage::class);

        return (new $class)->newQuery()->withoutGlobalScopes();
    }

    /**
     * @return Builder<EmailMessageEvent>
     */
    protected function eventQuery()
    {
        $class = config('postmaster.persistence.event_model', EmailMessageEvent::class);

        return (new $class)->newQuery()->withoutGlobalScopes();
    }

    /**
     * @return Builder<EmailAddress>
     */
    protected function addressQuery()
    {
        $class = config('postmaster.persistence.address_model', EmailAddress::class);

        return (new $class)->newQuery()->withoutGlobalScopes();
    }

    /**
     * The most recent timeline events, newest first, each with its message
     * eager-loaded across tenants. Shared by the activity stream and the
     * overview's recent-activity card.
     *
     * @param int $after Only events with a higher id (for the live feed).
     * @param int $limit
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EmailMessageEvent>
     */
    protected function recentEvents( $after = 0, $limit = 100 )
    {
        $query = $this->eventQuery()
            ->with(['emailMessage' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('id')
            ->limit($limit);

        if ($after > 0) {
            $query->where('id', '>', $after);
        }

        return $query->get();
    }

    /**
     * Flatten a timeline event for the JSON feed and the Alpine table.
     *
     * @param EmailMessageEvent $event
     *
     * @return array<string, mixed>
     */
    protected function presentEvent( $event )
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
