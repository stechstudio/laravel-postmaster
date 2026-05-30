<?php

namespace STS\Postmaster\Http\Controllers\Dashboard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;

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
    protected function messageQuery(): Builder
    {
        return EmailMessage::model()->newQuery()->withoutGlobalScopes();
    }

    /**
     * @return Builder<EmailActivity>
     */
    protected function activityQuery(): Builder
    {
        return EmailActivity::model()->newQuery()->withoutGlobalScopes();
    }

    /**
     * @return Builder<EmailAddress>
     */
    protected function addressQuery(): Builder
    {
        return EmailAddress::model()->newQuery()->withoutGlobalScopes();
    }

    /**
     * The most recent timeline activity, newest first, each entry with its
     * message eager-loaded across tenants. Shared by the activity stream and
     * the overview's recent-activity card. $after limits to entries with a
     * higher id (for the live feed).
     *
     * @return Collection<int, EmailActivity>
     */
    protected function recentActivity(int $after = 0, int $limit = 100): Collection
    {
        $query = $this->activityQuery()
            ->with([
                'emailMessage' => fn ($q) => $q->withoutGlobalScopes(),
                'emailAddress',
            ])
            ->orderByDesc('id')
            ->limit($limit);

        if ($after > 0) {
            $query->where('id', '>', $after);
        }

        return $query->get();
    }

    /**
     * The configured tenant model class, or null when tenancy is not in use.
     */
    protected function tenantModel(): ?string
    {
        return config('postmaster.persistence.tenant_model');
    }

    /**
     * The dashboard's word for a tenant, used for column headers, the filter
     * label, and the message-detail field. Derived from the configured
     * tenant model's class name (App\Models\Account => "Account"), so the
     * dashboard speaks the app's language; defaults to "Tenant".
     */
    protected function tenantTerm(): string
    {
        $model = $this->tenantModel();

        return $model ? class_basename($model) : 'Tenant';
    }

    /**
     * Distinct tenant keys that appear in the messages table.
     *
     * @return array<int, mixed>
     */
    protected function tenantKeysInUse(): array
    {
        return $this->messageQuery()
            ->whereNotNull(EmailMessage::tenantColumn())
            ->distinct()
            ->pluck(EmailMessage::tenantColumn())
            ->all();
    }

    /**
     * Resolve a [key => label] map for the given tenant keys. Labels come
     * from the tenant model — its "name", then "label", then its key — when
     * one is configured; otherwise the keys stand in for themselves.
     *
     * @param  array<int, mixed>            $keys
     * @return array<int|string, string>
     */
    protected function tenantLabels(array $keys): array
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
     */
    protected function tenantLabel(Model $tenant): string
    {
        return (string) ($tenant->getAttribute('name')
            ?? $tenant->getAttribute('label')
            ?? $tenant->getKey());
    }

    /**
     * A human label for the recipient model (the User). Tries "name", then
     * "email", then "label", then the class basename plus the key. Pulled
     * from already-loaded attributes only — no extra queries.
     */
    protected function recipientLabel(Model $recipient): string
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
     * the app has registered. Returns null if the type does not resolve to
     * a known class.
     */
    protected function resolveMorphClass(string $type): ?string
    {
        $mapped = Relation::getMorphedModel($type);

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
     */
    protected function applyContains(Builder $query, string $column, mixed $term): void
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
     */
    protected function applyDateRange(Builder $query, string $column, mixed $from, mixed $to): void
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
    protected function statuses(): array
    {
        return [
            EmailEvent::STATUS_SENT,
            EmailEvent::STATUS_SANDBOXED,
            EmailEvent::STATUS_BLOCKED,
            EmailEvent::STATUS_LOGGED,
            EmailEvent::STATUS_CAPTURED,
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
     * Flatten a timeline activity entry for the JSON feed and the Alpine
     * live-feed table.
     *
     * @return array<string, mixed>
     */
    protected function presentActivity(EmailActivity $entry): array
    {
        // Lifecycle entries (those tied to a message) read their headline
        // from the message. Address-only entries — manual suppress, sync
        // add, unsuppress — don't have a message, so they're labeled by
        // what happened at the address level, with the address itself
        // standing in as the recipient line.
        $subject = $entry->emailMessage?->getAttribute('subject');
        $to      = $entry->emailMessage?->getAttribute('to_address');

        if ($subject === null && $entry->emailAddress !== null) {
            $subject = match ($entry->status) {
                EmailActivity::STATUS_SUPPRESSED   => 'Address suppressed',
                EmailActivity::STATUS_UNSUPPRESSED => 'Address unsuppressed',
                default                            => 'Address activity',
            };
            $to = $entry->emailAddress->getAttribute('address');
        }

        return [
            'id'        => $entry->id,
            'status'    => $entry->status,
            'provider'  => $entry->provider,
            'to'        => $to,
            'subject'   => $subject,
            'messageId' => $entry->email_message_id,
            'at'        => $entry->occurred_at?->toIso8601String(),
        ];
    }
}
