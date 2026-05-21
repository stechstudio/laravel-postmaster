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
}
