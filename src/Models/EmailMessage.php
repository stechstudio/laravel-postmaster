<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use RuntimeException;
use STS\Postmaster\Attachments\InlineImages;
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EmailAttachment> $attachments
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

    /** Memoized result of previewBody(), which the detail view reads twice. */
    protected ?string $previewBody = null;

    protected bool $previewBodyResolved = false;

    protected $casts = [
        'recipients'    => 'array',
        'tags'          => 'array',
        'sent_at'       => 'datetime',
        'last_event_at' => 'datetime',
    ];

    /**
     * Deleting a message takes its timeline with it — the activity rows have
     * no database-level foreign key (email_message_id is a plain column), so
     * they'd otherwise be orphaned. The resent_from_id foreign key is
     * ON DELETE SET NULL, so any resends of this message simply lose their
     * link rather than cascading away.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $message) {
            $message->activity()->delete();
        });
    }

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
     * This row's tenant key. Saves every caller from spelling out
     * `$message->{EmailMessage::tenantColumn()}` — which the dashboard views
     * were doing by re-reading the config themselves, so the "single source
     * of truth" above had four copies.
     */
    public function tenantKey(): int|string|null
    {
        return $this->getAttribute(static::tenantColumn());
    }

    /**
     * Whether the row still holds a body to replay. Resending and releasing
     * both need one; pruning clears the content columns but keeps the row,
     * so a message can outlive its own content.
     */
    public function hasStoredContent(): bool
    {
        return (bool) ($this->html_body ?: $this->text_body);
    }

    /**
     * Whether this message's recipient is on the local suppression list.
     * False when there's no recipient recorded at all — callers asking this
     * are gating a send, and a row with no address is unsendable for its own
     * reason, which they report separately.
     */
    public function recipientIsSuppressed(): bool
    {
        return $this->to_address !== null && Postmaster::isSuppressed($this->to_address);
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
     * The attachments this email carried. Keyed on provider_message_id rather
     * than this row's id, because one submission writes a row per envelope
     * recipient and they all carried the same files — so To, Cc, and Bcc rows
     * resolve one shared set instead of three copies.
     */
    public function attachments(): HasMany
    {
        $model = config('postmaster.persistence.attachment_model', EmailAttachment::class);

        return $this->hasMany($model, 'provider_message_id', 'provider_message_id');
    }

    /**
     * The attachments whose bytes are still on disk. Resend and release
     * reattach these; the rest survive as metadata only.
     *
     * @return Collection<int, EmailAttachment>
     */
    public function availableAttachments(): Collection
    {
        return $this->attachments->filter->isAvailable()->values();
    }

    /**
     * How many of this message's attachments a replay can no longer carry —
     * never captured, oversize, pruned, or evicted. Drives the resend/release
     * warning, so an operator knows before clicking that the copy going out
     * won't be complete.
     */
    public function missingAttachmentCount(): int
    {
        return $this->attachments->count() - $this->availableAttachments()->count();
    }

    /**
     * Whether the recipient would have seen a paperclip on this message.
     * Embedded images don't count — a logo isn't something you'd say the
     * email came "with".
     */
    public function carriesFiles(): bool
    {
        // The list view loads the count as an aggregate so a page of rows
        // costs one query; the detail view already holds the relation. The
        // fallback keeps this correct anywhere else it gets called.
        return ($this->file_attachments_count ?? $this->fileAttachments()->count()) > 0;
    }

    /**
     * Counts each message's real attachments in the same query as the rows,
     * so a listing can flag which ones carried files without a query per row.
     */
    public function scopeWithFileAttachmentCount(Builder $query): Builder
    {
        return $query->withCount([
            'attachments as file_attachments_count' => fn (Builder $q) => $q->where('disposition', 'attachment'),
        ]);
    }

    /**
     * The real attachments — what the recipient would see paperclipped to the
     * message. Embedded images are excluded: a logo isn't an attachment, it's
     * part of the body, and listing it on every templated email would bury
     * the invoice.
     *
     * @return Collection<int, EmailAttachment>
     */
    public function fileAttachments(): Collection
    {
        return $this->attachments->where('disposition', 'attachment')->values();
    }

    /**
     * A one-line summary of what the message carried — "2 files · 727 B".
     * The total covers every file listed, including those whose bytes have
     * since gone: the size is recorded on capture and outlives them.
     */
    public function attachmentSummary(): string
    {
        $files = $this->fileAttachments();

        return $files->count().' '.Str::plural('file', $files->count())
            .' · '.EmailAttachment::humanBytes((int) $files->sum('size'));
    }

    /**
     * The stored html body with its `cid:` references resolved into inline
     * images, ready to drop into the dashboard preview. Memoized: the preview
     * asks for it and then asks whether anything failed to resolve, and
     * base64-encoding the bytes twice would be wasteful.
     */
    public function previewBody(): ?string
    {
        if (! $this->previewBodyResolved) {
            $this->previewBody = app(InlineImages::class)->resolve($this->html_body, $this->attachments);
            $this->previewBodyResolved = true;
        }

        return $this->previewBody;
    }

    /**
     * Whether the preview still references an embedded image we couldn't
     * supply — never captured, pruned, evicted, or too large to inline.
     */
    public function hasUnresolvedInlineImages(): bool
    {
        return app(InlineImages::class)->hasUnresolved($this->previewBody());
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
     * resent_from_id. Requires stored content; attachments come along when
     * their bytes are still stored.
     *
     * Throws \RuntimeException when there is no stored content to replay.
     */
    public function resend(): ?\Illuminate\Mail\SentMessage
    {
        return Postmaster::resend($this);
    }

    /**
     * Release this message if it was sandboxed — send it for real and flip
     * the row to "sent". See Postmaster::release() for the full contract.
     *
     * Throws \RuntimeException when the message is not sandboxed (nothing to
     * release, or already released) or has no stored content to send.
     */
    public function release(): ?\Illuminate\Mail\SentMessage
    {
        return Postmaster::release($this);
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
