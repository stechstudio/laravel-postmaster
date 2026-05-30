<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

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

    /**
     * The email this activity entry belongs to, if any. Address-only entries
     * (manual suppression, unsuppression, sync add/clear) have no message.
     */
    public function emailMessage(): BelongsTo
    {
        $model = config('postmaster.persistence.model', EmailMessage::class);

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
