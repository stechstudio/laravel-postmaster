<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;
use STS\Postmaster\Concerns\HasStatusPredicates;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Facades\Postmaster;

/**
 * A record of an outbound email and its delivery lifecycle.
 *
 * Only used when persistence is enabled. The model is swappable via the
 * "postmaster.persistence.message_model" config key.
 *
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property string|null $to_address
 * @property string|null $recipient_role  'to' | 'cc' | 'bcc'
 * @property string|null $recipient_type
 * @property int|string|null $recipient_id
 * @property int|null $resent_from_id
 * @property string|null $subject
 * @property string|null $from_address
 * @property array|null $recipients
 * @property string|null $html_body
 * @property string|null $text_body
 * @property array|null $attachments
 * @property array|null $tags
 * @property string|null $status
 * @property string|null $bounce_type
 * @property string|null $related_type
 * @property int|string|null $related_id
 * @property int|string|null $tenant_id
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $last_event_at
 */
class EmailMessage extends Model
{
    use HasStatusPredicates;

    /**
     * Statuses that mean the email did not reach the recipient. Exposed so a
     * caller can test an already-loaded record without re-deriving the set.
     *
     * @var array<int, string>
     */
    public const array FAILED_STATUSES = [
        EmailEvent::STATUS_BOUNCED,
        EmailEvent::STATUS_DROPPED,
        EmailEvent::STATUS_COMPLAINED,
    ];

    protected $guarded = [];

    protected $casts = [
        'recipients'    => 'array',
        'attachments'   => 'array',
        'tags'          => 'array',
        'sent_at'       => 'datetime',
        'last_event_at' => 'datetime',
    ];

    /**
     * A fresh instance of the configured (swappable) email message model. Use
     * this anywhere a query starts from — `EmailMessage::model()->newQuery()…`
     * — instead of `new (static::class)`, so an app that swapped in a custom
     * subclass via persistence.message_model gets that subclass everywhere.
     */
    public static function model(): self
    {
        $class = config('postmaster.persistence.message_model', static::class);

        return new $class;
    }

    public function getTable(): string
    {
        return config('postmaster.persistence.messages_table', 'email_messages');
    }

    /**
     * Used by HasStatusPredicates to drive the is*() methods. Returns the
     * latest status recorded for this message.
     */
    protected function currentStatus(): ?string
    {
        return $this->getAttribute('status');
    }

    public function getConnectionName()
    {
        return config('postmaster.persistence.connection') ?: parent::getConnectionName();
    }

    /**
     * The configured tenant column name on the email messages table. Single
     * source of truth — every other layer (listeners, controllers, the
     * ResentMessage builder) delegates here so the config key is read in one
     * place.
     */
    public static function tenantColumn(): string
    {
        return config('postmaster.persistence.tenant_column', 'tenant_id');
    }

    /**
     * The application model this email was sent for, if any.
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The application model the email was sent *to* — usually a User. Set
     * via a Mailable's Tracking(recipient: ...) or by an app-registered
     * Postmaster::resolveRecipientUsing() resolver. Independent of related().
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The full delivery timeline — the send and every webhook event, oldest
     * first. Only populated when "postmaster.persistence.record_events" is
     * on. Each row is an EmailActivity entry.
     */
    public function activity(): HasMany
    {
        $model = config('postmaster.persistence.activity_model', EmailActivity::class);

        return $this->hasMany($model, 'email_message_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * The tenant this email belongs to. Requires the tenant model class to
     * be set via the "postmaster.persistence.tenant_model" config key.
     */
    public function tenant(): BelongsTo
    {
        $model = config('postmaster.persistence.tenant_model');

        if (! $model) {
            throw new RuntimeException(
                'Set postmaster.persistence.tenant_model to use the tenant() relationship.'
            );
        }

        return $this->belongsTo($model, static::tenantColumn());
    }

    /**
     * Scope to the email activity of a single tenant.
     *
     * @param Model|int|string $tenant A tenant model or its key.
     */
    public function scopeForTenant(Builder $query, Model|int|string $tenant): Builder
    {
        $key = $tenant instanceof Model ? $tenant->getKey() : $tenant;

        return $query->where(static::tenantColumn(), $key);
    }

    /**
     * Scope to messages at a given lifecycle status.
     *
     * @param string $status One of the EmailEvent::STATUS_* constants.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_SENT);
    }

    /**
     * Scope to messages intercepted by sandbox delivery — recorded but never
     * actually sent.
     */
    public function scopeSandbox(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_SANDBOXED);
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_ACCEPTED);
    }

    public function scopeDeferred(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_DEFERRED);
    }

    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_DELIVERED);
    }

    public function scopeBounced(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_BOUNCED);
    }

    public function scopeDropped(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_DROPPED);
    }

    public function scopeComplained(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_COMPLAINED);
    }

    /**
     * Scope to messages that did not reach the recipient — bounced, dropped,
     * or complained. The complement of delivered().
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', self::FAILED_STATUSES);
    }

    public function scopeOpened(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_OPENED);
    }

    public function scopeClicked(Builder $query): Builder
    {
        return $query->where('status', EmailEvent::STATUS_CLICKED);
    }

    /**
     * Scope to messages carrying the given tag.
     */
    public function scopeTaggedWith(Builder $query, string $tag): Builder
    {
        return $query->whereJsonContains('tags', $tag);
    }

    /**
     * The original message this row is a resend of, or null if this row
     * was a fresh send (or pre-dates the resend tracking feature).
     */
    public function resentFrom(): BelongsTo
    {
        return $this->belongsTo(static::class, 'resent_from_id');
    }

    /**
     * Every message that was sent as a resend of this row. Direct children
     * only — a resend of a resend is in *that* row's resends(), not here.
     * Walk the full tree with resendChain().
     */
    public function resends(): HasMany
    {
        return $this->hasMany(static::class, 'resent_from_id');
    }

    /**
     * Replay this message through the configured mailer, preserving its
     * sender, recipients, subject, bodies, and tracking context (plus a
     * `resent` tag of its own). The new row links back to this one via
     * resent_from_id. Requires stored content; attachments are not restored.
     *
     * Throws \RuntimeException when there is no stored content to replay.
     */
    public function resend(): ?\Illuminate\Mail\SentMessage
    {
        return Postmaster::resend($this);
    }

    /**
     * Every message in this row's resend chain — the original at the root,
     * each subsequent resend below it, ordered by send time. Useful for
     * the dashboard's chain card and for answering "did any retry of this
     * eventually deliver?" without recursing in app code.
     *
     * Walks the FK in both directions: ancestors via resent_from_id all
     * the way up to the root, then descendants of the root down through
     * resends() — so the result is always the same regardless of which
     * link in the chain it was called on.
     *
     * @return Collection<int, EmailMessage>
     */
    public function resendChain(): Collection
    {
        $root = $this;

        while ($root->resentFrom) {
            $root = $root->resentFrom;
        }

        return static::descendantsOf($root);
    }

    /**
     * Internal: collect $root and every descendant of it via the
     * resent_from_id FK, ordered by send time. Recursive in PHP rather
     * than a CTE so we stay portable across the database engines the
     * package supports.
     *
     * @return Collection<int, EmailMessage>
     */
    protected static function descendantsOf(EmailMessage $root): Collection
    {
        /** @var Collection<int, EmailMessage> $chain */
        $chain = new Collection([$root]);

        /** @var EmailMessage $child */
        foreach ($root->resends()->withoutGlobalScopes()->orderBy('sent_at')->get() as $child) {
            $chain = $chain->merge(static::descendantsOf($child));
        }

        return $chain;
    }
}
