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
 * "postmaster.persistence.model" config key.
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

    public function getTable()
    {
        return config('postmaster.persistence.table', 'email_messages');
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
     * The configured tenant column name.
     *
     * @return string
     */
    public function tenantColumn()
    {
        return config('postmaster.persistence.tenant_column', 'tenant_id');
    }

    /**
     * The application model this email was sent for, if any.
     *
     * @return MorphTo
     */
    public function related()
    {
        return $this->morphTo();
    }

    /**
     * The application model the email was sent *to* — usually a User. Set
     * via a Mailable's Tracking(recipient: ...) or by an app-registered
     * Postmaster::resolveRecipientUsing() resolver. Independent of related().
     *
     * @return MorphTo
     */
    public function recipient()
    {
        return $this->morphTo();
    }

    /**
     * The full delivery timeline — the send and every webhook event, oldest
     * first. Only populated when "postmaster.persistence.record_events" is
     * on. Each row is an EmailActivity entry.
     *
     * @return HasMany
     */
    public function activity()
    {
        $model = config('postmaster.persistence.activity_model', EmailActivity::class);

        return $this->hasMany($model, 'email_message_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * The tenant this email belongs to. Requires the tenant model class to
     * be set via the "postmaster.persistence.tenant_model" config key.
     *
     * @return BelongsTo
     */
    public function tenant()
    {
        $model = config('postmaster.persistence.tenant_model');

        if (! $model) {
            throw new RuntimeException(
                'Set postmaster.persistence.tenant_model to use the tenant() relationship.'
            );
        }

        return $this->belongsTo($model, $this->tenantColumn());
    }

    /**
     * Scope to the email activity of a single tenant.
     *
     * @param Builder          $query
     * @param Model|int|string $tenant A tenant model or its key.
     *
     * @return Builder
     */
    public function scopeForTenant( Builder $query, $tenant )
    {
        $key = $tenant instanceof Model ? $tenant->getKey() : $tenant;

        return $query->where($this->tenantColumn(), $key);
    }

    /**
     * Scope to messages at a given lifecycle status.
     *
     * @param Builder $query
     * @param string  $status One of the EmailEvent::STATUS_* constants.
     *
     * @return Builder
     */
    public function scopeWithStatus( Builder $query, $status )
    {
        return $query->where('status', $status);
    }

    /** @return Builder */
    public function scopeSent( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_SENT);
    }

    /**
     * Scope to messages intercepted by sandbox delivery — recorded but never
     * actually sent.
     *
     * @return Builder
     */
    public function scopeSandbox( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_SANDBOXED);
    }

    /** @return Builder */
    public function scopeAccepted( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_ACCEPTED);
    }

    /** @return Builder */
    public function scopeDeferred( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_DEFERRED);
    }

    /** @return Builder */
    public function scopeDelivered( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_DELIVERED);
    }

    /** @return Builder */
    public function scopeBounced( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_BOUNCED);
    }

    /** @return Builder */
    public function scopeDropped( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_DROPPED);
    }

    /** @return Builder */
    public function scopeComplained( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_COMPLAINED);
    }

    /**
     * Scope to messages that did not reach the recipient — bounced, dropped,
     * or complained. The complement of delivered().
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeFailed( Builder $query )
    {
        return $query->whereIn('status', self::FAILED_STATUSES);
    }

    /** @return Builder */
    public function scopeOpened( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_OPENED);
    }

    /** @return Builder */
    public function scopeClicked( Builder $query )
    {
        return $query->where('status', EmailEvent::STATUS_CLICKED);
    }

    /**
     * Scope to messages carrying the given tag.
     *
     * @param Builder $query
     * @param string  $tag
     *
     * @return Builder
     */
    public function scopeTaggedWith( Builder $query, $tag )
    {
        return $query->whereJsonContains('tags', $tag);
    }

    /**
     * The original message this row is a resend of, or null if this row
     * was a fresh send (or pre-dates the resend tracking feature).
     *
     * @return BelongsTo
     */
    public function resentFrom()
    {
        return $this->belongsTo(static::class, 'resent_from_id');
    }

    /**
     * Every message that was sent as a resend of this row. Direct children
     * only — a resend of a resend is in *that* row's resends(), not here.
     * Walk the full tree with resendChain().
     *
     * @return HasMany
     */
    public function resends()
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
     *
     * @return \Illuminate\Mail\SentMessage|null
     */
    public function resend()
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
    public function resendChain()
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
     * @param EmailMessage $root
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
