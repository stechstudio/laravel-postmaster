<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single entry in an email's delivery timeline — the initial send, or one
 * webhook event. Unlike EmailMessage (which holds only the latest status),
 * every event is kept, so repeated opens and clicks are all preserved.
 *
 * Only used when persistence and persistence.record_events are enabled. The
 * model is swappable via the "postmaster.persistence.event_model" config key.
 *
 * @property int $email_message_id
 * @property string|null $provider
 * @property string|null $status
 * @property string|null $bounce_type
 * @property string|null $response
 * @property string|null $reason
 * @property string|null $code
 * @property string|null $url
 * @property \Illuminate\Support\Carbon|null $occurred_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class EmailMessageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('postmaster.persistence.events_table', 'email_message_events');
    }

    public function getConnectionName()
    {
        return config('postmaster.persistence.connection') ?: parent::getConnectionName();
    }

    /**
     * The email this event belongs to.
     *
     * @return BelongsTo
     */
    public function emailMessage()
    {
        $model = config('postmaster.persistence.model', EmailMessage::class);

        return $this->belongsTo($model, 'email_message_id');
    }
}
