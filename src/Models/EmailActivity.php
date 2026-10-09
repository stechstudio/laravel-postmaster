<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use STS\Postmaster\EmailEvent;

/**
 * A single recorded entry in the email_activity table. Two shapes share the
 * same row:
 *
 *   - A message lifecycle entry (sent, delivered, opened, bounced, …) has
 *     email_message_id set; email_address_id is set too when the event
 *     identifies a recipient (most do).
 *   - An address-only entry (manually suppressed, unsuppressed, added by
 *     sync, …) has only email_address_id set, with no specific message.
 *
 * EmailEvent is the live in-memory signal a webhook becomes when it arrives;
 * EmailActivity is the historical record we keep of it. The names are kept
 * distinct deliberately.
 *
 * Only used when persistence and persistence.record_events are enabled. The
 * model is swappable via the "postmaster.persistence.activity_model" config
 * key.
 *
 * @property int|null $email_message_id
 * @property int|null $email_address_id
 * @property string|null $provider
 * @property string|null $status
 * @property string|null $bounce_type
 * @property string|null $response
 * @property string|null $reason
 * @property string|null $code
 * @property string|null $url
 * @property string|null $causer_type
 * @property int|string|null $causer_id
 * @property string|null $source
 * @property \Illuminate\Support\Carbon|null $occurred_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class EmailActivity extends Model
{
    /**
     * Address-level statuses — used on activity rows whose
     * email_message_id is null. Message-level statuses live on EmailEvent
     * because they're shared with the live webhook value object.
     */
    public const string STATUS_SUPPRESSED   = 'suppressed';
    public const string STATUS_UNSUPPRESSED = 'unsuppressed';

    /** Events that mean the email did not reach the recipient. */
    public const array PROBLEM_STATUSES = [
        EmailEvent::STATUS_BOUNCED,
        EmailEvent::STATUS_DROPPED,
        EmailEvent::STATUS_COMPLAINED,
        EmailEvent::STATUS_BLOCKED,
    ];

    /** An RFC 3463 enhanced status code, such as 5.4.1. */
    protected const string SMTP_STATUS_PATTERN = '/\b[245]\.\d{1,3}\.\d{1,3}\b/';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    /** Retain the provider's timestamp precision without changing other dates. */
    public function setOccurredAtAttribute(mixed $value): void
    {
        $this->attributes['occurred_at'] = $value === null ? null : \Carbon\CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.u');
    }

    /**
     * A fresh instance of the configured (swappable) email activity model.
     * Use this anywhere a query starts from — `EmailActivity::model()->newQuery()…`
     * — so an app that swapped in a custom subclass via
     * persistence.activity_model gets that subclass everywhere.
     */
    public static function model(): self
    {
        $class = config('postmaster.persistence.activity_model', static::class);

        return new $class;
    }

    public function getTable(): string
    {
        return config('postmaster.persistence.activity_table', 'email_activity');
    }

    public function getConnectionName()
    {
        return config('postmaster.persistence.connection') ?: parent::getConnectionName();
    }

    public function isProblem(): bool
    {
        return in_array($this->status, self::PROBLEM_STATUSES, true);
    }

    /**
     * The enhanced status code (5.4.1) from the server's response, falling
     * back to the provider's code when that is one. Postmark's code is its
     * own bounce type number, not an SMTP status, so it never matches.
     */
    public function smtpStatus(): ?string
    {
        foreach ([$this->response, $this->code] as $value) {
            if ($value !== null && preg_match(self::SMTP_STATUS_PATTERN, $value, $match)) {
                return $match[0];
            }
        }

        return null;
    }

    /** What went wrong, in one plain sentence. */
    public function summary(): ?string
    {
        return match ($this->status) {
            EmailEvent::STATUS_BOUNCED => match ($this->bounce_type) {
                EmailEvent::BOUNCE_HARD  => 'The receiving mail server permanently rejected this email.',
                EmailEvent::BOUNCE_SOFT  => 'The receiving mail server temporarily rejected this email.',
                EmailEvent::BOUNCE_BLOCK => 'The receiving mail server refused this email on reputation or policy grounds.',
                default                  => 'This email bounced.',
            },
            EmailEvent::STATUS_DROPPED    => ($this->provider ?? 'The mail provider').' did not send this email.',
            EmailEvent::STATUS_COMPLAINED => 'The recipient marked this email as spam.',
            EmailEvent::STATUS_BLOCKED    => 'Postmaster did not send this email because the address is on the suppression list.',
            default                       => null,
        };
    }

    /**
     * For a send the suppression list stopped, the earlier failure that put
     * the address there: the latest hard bounce or complaint before it, since
     * the address was last unsuppressed. A drop says only that the address was
     * suppressed; this is the event that says why.
     *
     * Null for any other event, and when the ledger holds no such failure
     * (a manual suppression, or one learned from a provider sync). The cause
     * may sit on another tenant's message, since suppression is global.
     */
    public function suppressionCause(): ?self
    {
        if (! in_array($this->status, [EmailEvent::STATUS_DROPPED, EmailEvent::STATUS_BLOCKED], true)
            || $this->email_address_id === null || $this->occurred_at === null) {
            return null;
        }

        $earlier = static::model()->newQuery()
            ->where('email_address_id', $this->email_address_id)
            ->where('occurred_at', '<=', $this->occurred_at)
            ->whereKeyNot($this->getKey());

        $unsuppressedAt = (clone $earlier)->where('status', self::STATUS_UNSUPPRESSED)->max('occurred_at');

        return $earlier
            ->when($unsuppressedAt, fn ($query) => $query->where('occurred_at', '>', $unsuppressedAt))
            ->where(fn ($query) => $query
                ->where('status', EmailEvent::STATUS_COMPLAINED)
                ->orWhere(fn ($query) => $query
                    ->where('status', EmailEvent::STATUS_BOUNCED)
                    ->where(fn ($query) => $query->whereNull('bounce_type')->orWhere('bounce_type', '!=', EmailEvent::BOUNCE_SOFT))))
            ->latest('occurred_at')
            ->latest('id')
            ->first();
    }

    /**
     * The email this activity entry belongs to, if any. Address-only entries
     * (manual suppression, unsuppression, sync add/clear) have no message.
     */
    public function emailMessage(): BelongsTo
    {
        $model = config('postmaster.persistence.message_model', EmailMessage::class);

        return $this->belongsTo($model, 'email_message_id');
    }

    /**
     * The recipient address this activity entry concerns, if any. Most
     * lifecycle entries carry one; the few that don't (entries for messages
     * with no usable recipient on the webhook payload) leave this null.
     */
    public function emailAddress(): BelongsTo
    {
        $model = config('postmaster.persistence.address_model', EmailAddress::class);

        return $this->belongsTo($model, 'email_address_id');
    }

    /**
     * Who acted, when the entry was operator-initiated. Resolved through
     * Laravel's morph map (causer_type stores 'user', not the consumer's
     * FQCN) so the relation is decoupled from app class names.
     *
     * Null on entries with no model actor — anything written by the sync
     * command, a webhook, or any automatic source — and on installs whose
     * email_activity table lives on a different DB connection than the
     * consumer's users table, where this relation can't be hydrated across
     * the boundary. In both cases the `source` column carries the label.
     */
    public function causer(): MorphTo
    {
        return $this->morphTo('causer');
    }
}
