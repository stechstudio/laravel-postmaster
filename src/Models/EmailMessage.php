<?php

namespace STS\EmailEvents\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A record of an outbound email and its delivery lifecycle.
 *
 * Only used when persistence is enabled. The model is swappable via the
 * "email-events.persistence.model" config key.
 *
 * @property string|null $provider
 * @property string|null $message_id
 * @property string|null $recipient
 * @property string|null $subject
 * @property string|null $status
 * @property string|null $bounce_type
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $last_event_at
 */
class EmailMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sent_at'       => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('email-events.persistence.table', 'email_messages');
    }
}
