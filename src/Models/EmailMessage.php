<?php

namespace STS\EmailEvents\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

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
        'sent_at'       => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('email-events.persistence.table', 'email_messages');
    }

    public function getConnectionName()
    {
        return config('email-events.persistence.connection') ?: parent::getConnectionName();
    }

    /**
     * The configured tenant column name.
     *
     * @return string
     */
    public function tenantColumn()
    {
        return config('email-events.persistence.tenant_column', 'tenant_id');
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
     * The tenant this email belongs to. Requires the tenant model class to
     * be set via the "email-events.persistence.tenant_model" config key.
     *
     * @return BelongsTo
     */
    public function tenant()
    {
        $model = config('email-events.persistence.tenant_model');

        if (! $model) {
            throw new RuntimeException(
                'Set email-events.persistence.tenant_model to use the tenant() relationship.'
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
}
