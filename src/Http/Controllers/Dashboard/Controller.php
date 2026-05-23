<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\EmailEvent;
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
     * The configured tenant column name.
     *
     * @return string
     */
    protected function tenantColumn()
    {
        return config('postmaster.persistence.tenant_column', 'tenant_id');
    }

    /**
     * The configured tenant model class, or null when tenancy is not in use.
     *
     * @return string|null
     */
    protected function tenantModel()
    {
        return config('postmaster.persistence.tenant_model');
    }

    /**
     * The dashboard's word for a tenant, used for column headers, the filter
     * label, and the message-detail field. Derived from the configured
     * tenant model's class name (App\Models\Account => "Account"), so the
     * dashboard speaks the app's language; defaults to "Tenant".
     *
     * @return string
     */
    protected function tenantTerm()
    {
        $model = $this->tenantModel();

        return $model ? class_basename($model) : 'Tenant';
    }

    /**
     * Distinct tenant keys that appear in the messages table.
     *
     * @return array
     */
    protected function tenantKeysInUse()
    {
        return $this->messageQuery()
            ->whereNotNull($this->tenantColumn())
            ->distinct()
            ->pluck($this->tenantColumn())
            ->all();
    }

    /**
     * Resolve a [key => label] map for the given tenant keys. Labels come
     * from the tenant model — its "name", then "label", then its key — when
     * one is configured; otherwise the keys stand in for themselves.
     *
     * @param array $keys
     *
     * @return array<int|string, string>
     */
    protected function tenantLabels( array $keys )
    {
        $keys = array_values(array_unique(array_filter(
            array_map(fn ($key) => $key === null ? null : (string) $key, $keys),
            fn ($key) => $key !== null && $key !== ''
        )));

        if (empty($keys)) {
            return [];
        }

        if ($this->tenantModel() === null) {
            return array_combine($keys, $keys);
        }

        return $this->messageQuery()->getModel()->tenant()->getRelated()->newQuery()
            ->withoutGlobalScopes()
            ->whereKey($keys)
            ->get()
            ->mapWithKeys(fn (Model $tenant) => [$tenant->getKey() => $this->tenantLabel($tenant)])
            ->all();
    }

    /**
     * A human label for a tenant model: "name", then "label", then its key.
     *
     * @param Model $tenant
     *
     * @return string
     */
    protected function tenantLabel( Model $tenant )
    {
        return (string) ($tenant->getAttribute('name')
            ?? $tenant->getAttribute('label')
            ?? $tenant->getKey());
    }

    /**
     * A human label for the recipient model (the User). Tries "name", then
     * "email", then "label", then the class basename plus the key. Pulled
     * from already-loaded attributes only — no extra queries.
     *
     * @param Model $recipient
     *
     * @return string
     */
    protected function recipientLabel( Model $recipient )
    {
        $name = $recipient->getAttribute('name')
            ?? $recipient->getAttribute('email')
            ?? $recipient->getAttribute('label');

        if ($name) {
            return (string) $name;
        }

        return class_basename($recipient).' #'.$recipient->getKey();
    }

    /**
     * Resolve a morph type to its class — applying any Relation::morphMap()
     * the app has registered.
     *
     * @param string $type
     *
     * @return string|null  The fully-qualified class name, or null if the
     *                      type does not resolve to a known class.
     */
    protected function resolveMorphClass( $type )
    {
        $mapped = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type);

        if ($mapped !== null) {
            return $mapped;
        }

        return class_exists($type) ? $type : null;
    }

    /**
     * Add a case-insensitive "contains" filter on a column. lower() keeps it
     * portable across the database engines the package supports. A no-op for
     * a term under three characters. The column name is supplied by the
     * controller, never by the request.
     *
     * @param Builder $query
     * @param string  $column
     * @param mixed   $term
     *
     * @return void
     */
    protected function applyContains( Builder $query, $column, $term )
    {
        $term = trim((string) ($term ?? ''));

        // Ignore terms under three characters: they barely narrow the result
        // set yet still force an unindexed full-table scan. The filter inputs
        // enforce the same minimum; a hand-typed URL would otherwise skip it.
        if (strlen($term) < 3) {
            return;
        }

        $query->whereRaw("lower({$column}) like ?", ['%'.strtolower($term).'%']);
    }

    /**
     * Add an inclusive date-range filter on a column. Either bound may be
     * empty, in which case that side is left open.
     *
     * @param Builder $query
     * @param string  $column
     * @param mixed   $from
     * @param mixed   $to
     *
     * @return void
     */
    protected function applyDateRange( Builder $query, $column, $from, $to )
    {
        if ($from !== null && $from !== '') {
            $query->where($column, '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->where($column, '<=', $to.' 23:59:59');
        }
    }

    /**
     * The lifecycle statuses, for filter dropdowns.
     *
     * @return array<int, string>
     */
    protected function statuses()
    {
        return [
            EmailEvent::STATUS_SENT,
            EmailEvent::STATUS_SANDBOX,
            EmailEvent::STATUS_ACCEPTED,
            EmailEvent::STATUS_DEFERRED,
            EmailEvent::STATUS_DELIVERED,
            EmailEvent::STATUS_BOUNCED,
            EmailEvent::STATUS_DROPPED,
            EmailEvent::STATUS_COMPLAINED,
            EmailEvent::STATUS_OPENED,
            EmailEvent::STATUS_CLICKED,
        ];
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
            'to'        => $event->emailMessage?->getAttribute('to_address'),
            'subject'   => $event->emailMessage?->getAttribute('subject'),
            'messageId' => $event->email_message_id,
            'at'        => $event->occurred_at?->format('M j, g:ia'),
        ];
    }
}
