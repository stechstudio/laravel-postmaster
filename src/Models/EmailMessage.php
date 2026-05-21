<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;
use STS\Postmaster\EmailEvent;

/**
 * A record of an outbound email and its delivery lifecycle.
 *
 * Only used when persistence is enabled. The model is swappable via the
 * "postmaster.persistence.model" config key.
 *
 * @property string|null $provider
 * @property string|null $message_id
 * @property string|null $recipient
 * @property string|null $subject
 * @property string|null $from_address
 * @property array|null $recipients
 * @property string|null $html_body
 * @property string|null $text_body
 * @property array|null $attachments
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
    protected $guarded = [];

    protected $casts = [
        'recipients'    => 'array',
        'attachments'   => 'array',
        'sent_at'       => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('postmaster.persistence.table', 'email_messages');
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
     * The full delivery timeline — the send and every webhook event, oldest
     * first. Only populated when "postmaster.persistence.record_events" is on.
     *
     * @return HasMany
     */
    public function events()
    {
        $model = config('postmaster.persistence.event_model', EmailMessageEvent::class);

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
     * @param string  $status One of the EmailEvent::EVENT_* constants.
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
        return $query->where('status', EmailEvent::EVENT_SENT);
    }

    /**
     * Scope to messages intercepted by sandbox delivery — recorded but never
     * actually sent.
     *
     * @return Builder
     */
    public function scopeSandbox( Builder $query )
    {
        return $query->where('status', EmailEvent::EVENT_SANDBOX);
    }

    /** @return Builder */
    public function scopeAccepted( Builder $query )
    {
        return $query->where('status', EmailEvent::EMAIL_ACCEPTED);
    }

    /** @return Builder */
    public function scopeDeferred( Builder $query )
    {
        return $query->where('status', EmailEvent::EVENT_DEFERRED);
    }

    /** @return Builder */
    public function scopeDelivered( Builder $query )
    {
        return $query->where('status', EmailEvent::EVENT_DELIVERED);
    }

    /** @return Builder */
    public function scopeBounced( Builder $query )
    {
        return $query->where('status', EmailEvent::EVENT_BOUNCED);
    }

    /** @return Builder */
    public function scopeDropped( Builder $query )
    {
        return $query->where('status', EmailEvent::EVENT_DROPPED);
    }

    /** @return Builder */
    public function scopeComplained( Builder $query )
    {
        return $query->where('status', EmailEvent::EVENT_COMPLAINED);
    }

    /** @return Builder */
    public function scopeOpened( Builder $query )
    {
        return $query->where('status', EmailEvent::EVENT_OPENED);
    }

    /** @return Builder */
    public function scopeClicked( Builder $query )
    {
        return $query->where('status', EmailEvent::EVENT_CLICKED);
    }
}
