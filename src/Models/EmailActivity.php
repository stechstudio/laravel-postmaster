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

    /**
     * The words of the server's response, without the "smtp;" prefix, the
     * leading status codes, or the trailing bracketed server trace.
     */
    public function responseText(): ?string
    {
        if ($this->response === null) {
            return null;
        }

        $text = preg_replace('/^\s*smtp\s*;\s*/i', '', $this->response);
        $text = preg_replace('/^\d{3}[\s-]+(?:[245]\.\d{1,3}\.\d{1,3}\s+)?/', '', $text);
        $text = trim(preg_replace('/\s*\[[^\]]*\]\s*$/', '', $text));

        return $text === '' ? null : $text;
    }

    /**
     * One plain paragraph a support tech can paste into a reply: what
     * happened, when, and what the receiving server or provider said.
     */
    public function summary(string $address): ?string
    {
        $what = match ($this->status) {
            EmailEvent::STATUS_BOUNCED => match ($this->bounce_type) {
                EmailEvent::BOUNCE_HARD  => "the receiving mail server permanently rejected the email to {$address}",
                EmailEvent::BOUNCE_SOFT  => "the receiving mail server temporarily rejected the email to {$address}",
                EmailEvent::BOUNCE_BLOCK => "the receiving mail server refused the email to {$address} on reputation or policy grounds",
                default                  => "the email to {$address} bounced",
            },
            EmailEvent::STATUS_DROPPED    => ($this->provider ?? 'the mail provider')." did not send the email to {$address}",
            EmailEvent::STATUS_COMPLAINED => "the recipient {$address} marked the email as spam",
            EmailEvent::STATUS_BLOCKED    => "Postmaster did not send the email to {$address} because the address is on the suppression list",
            default                       => null,
        };

        if ($what === null) {
            return null;
        }

        $sentences = [$this->occurred_at
            ? 'On '.$this->occurred_at->utc()->format('M j, Y \a\t H:i').' UTC, '.$what.'.'
            : ucfirst($what).'.'];

        if ($text = $this->responseText()) {
            $status = $this->smtpStatus();
            $sentences[] = 'The server said: "'.$text.'"'.($status ? " (SMTP status {$status})." : '');
        } elseif ($this->reason) {
            $sentences[] = "Reason: {$this->reason}.";
        }

        return implode(' ', $sentences);
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
