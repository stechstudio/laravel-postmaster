<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The current deliverability status of a single recipient address.
 *
 * This is the third and most collapsed projection: email_activity roll
 * up into email_messages, which roll up into one row per address here. It
 * answers "should I send to this address?" with a single indexed lookup,
 * rather than interpreting message history.
 *
 * Suppression is global by design — providers suppress on their side for a
 * hard bounce or complaint regardless of which of your tenants sent the mail,
 * so a per-tenant view would diverge from reality.
 *
 * Only used when persistence is enabled. The model is swappable via the
 * "postmaster.persistence.address_model" config key.
 *
 * @property string $address
 * @property string $status
 * @property string|null $reason
 * @property array<int, string>|null $providers
 * @property \Illuminate\Support\Carbon|null $suppressed_at
 * @property \Illuminate\Support\Carbon|null $last_event_at
 */
class EmailAddress extends Model
{
    public const string STATUS_ACTIVE     = 'active';
    public const string STATUS_SUPPRESSED = 'suppressed';

    public const string REASON_MANUAL     = 'manual';
    public const string REASON_BOUNCED    = 'bounced';
    public const string REASON_DROPPED    = 'dropped';
    public const string REASON_COMPLAINED = 'complained';

    /**
     * Reasons that count as "automatic" — recorded by the webhook stream
     * or by the suppression sync. Suppression sync may auto-clear these
     * when the provider's authoritative list no longer holds them.
     *
     * Manual suppressions, by contrast, are operator-asserted and never
     * cleared by sync; they're cleared only by an explicit unsuppress.
     *
     * @var array<int, string>
     */
    public const array AUTOMATIC_REASONS = [
        self::REASON_BOUNCED,
        self::REASON_DROPPED,
        self::REASON_COMPLAINED,
    ];

    protected $guarded = [];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $casts = [
        'providers'     => 'array',
        'suppressed_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    /**
     * Append a provider name to this row's providers list (deduped),
     * persisting the change. A no-op when the name is empty.
     *
     * @param string|null $provider
     *
     * @return void
     */
    public function recordProvider( $provider )
    {
        if (! is_string($provider) || $provider === '') {
            return;
        }

        $providers = $this->providers ?? [];

        if (in_array($provider, $providers, true)) {
            return;
        }

        $providers[] = $provider;
        $this->providers = $providers;
    }

    /**
     * Whether at least one provider in this row's `providers` list has a
     * usable suppression-sync API — i.e. an Unsuppress from the dashboard
     * could actually clear *something* upstream, not just lift the local
     * row. Manual suppressions and legacy rows (no providers recorded)
     * always report true; for them the local lift is the whole point.
     *
     * @return bool
     */
    public function canApiUnsuppress()
    {
        if ($this->reason === self::REASON_MANUAL) {
            return true;
        }

        $providers = $this->providers ?? [];

        if (empty($providers)) {
            return true;
        }

        $postmaster = app(\STS\Postmaster\Postmaster::class);

        foreach ($providers as $provider) {
            $sync = $postmaster->sync($provider);

            if ($sync !== null && $sync->isAvailable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Provider names attached to this row whose sync isn't available — i.e.
     * an Unsuppress action can't clear them via API; the operator has to
     * remove the address from each one's dashboard by hand. Used by the
     * dashboard to show a "manage in {provider}" hint.
     *
     * @return array<int, string>
     */
    public function providersWithoutApiUnsuppress()
    {
        $providers = $this->providers ?? [];

        if (empty($providers)) {
            return [];
        }

        $postmaster = app(\STS\Postmaster\Postmaster::class);

        return array_values(array_filter($providers, function ($provider) use ($postmaster) {
            $sync = $postmaster->sync($provider);

            return $sync === null || ! $sync->isAvailable();
        }));
    }

    public function getTable()
    {
        return config('postmaster.persistence.addresses_table', 'email_addresses');
    }

    public function getConnectionName()
    {
        return config('postmaster.persistence.connection') ?: parent::getConnectionName();
    }

    /**
     * Normalize an address for storage and lookup. Addresses are matched
     * case-insensitively, the same way every provider treats them.
     *
     * @param string $address
     *
     * @return string
     */
    public static function normalize( $address )
    {
        return strtolower(trim($address));
    }

    /**
     * Whether this address is currently suppressed.
     *
     * @return bool
     */
    public function isSuppressed()
    {
        return $this->status === self::STATUS_SUPPRESSED;
    }

    /**
     * Every activity entry attached to this address — events from a message
     * sent to it, plus any address-level entries (manual suppression,
     * unsuppression, sync add/clear) that target the address directly with
     * no specific message.
     *
     * @return HasMany
     */
    public function activity()
    {
        $model = config('postmaster.persistence.activity_model', EmailActivity::class);

        return $this->hasMany($model, 'email_address_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * Write an address-level activity entry against this row — used to
     * record manual suppressions, unsuppressions, and sync add/clear. The
     * row carries email_address_id but no email_message_id (there's no
     * specific message involved).
     *
     * A no-op when persistence.record_events is off.
     *
     * @param array<string, mixed> $attributes
     *
     * @return EmailActivity|null
     */
    public function logActivity( array $attributes )
    {
        if (! config('postmaster.persistence.record_events', true) || ! $this->exists) {
            return null;
        }

        $class = config('postmaster.persistence.activity_model', EmailActivity::class);

        return $class::create($attributes + [
            'email_address_id' => $this->getKey(),
            'occurred_at'      => $attributes['occurred_at'] ?? now(),
        ]);
    }

    /**
     * Mark this address suppressed. Suppression is sticky: a later delivery
     * or open never clears it — only an explicit unsuppress() does.
     *
     * @param string $reason
     *
     * @return $this
     */
    public function suppress( $reason = self::REASON_MANUAL )
    {
        $this->status = self::STATUS_SUPPRESSED;
        $this->reason = $reason;
        $this->suppressed_at = now();
        $this->save();

        return $this;
    }

    /**
     * Return this address to active — e.g. after the recipient fixes their
     * mailbox, or you clear the suppression on the provider's side.
     *
     * @return $this
     */
    public function unsuppress()
    {
        $this->status = self::STATUS_ACTIVE;
        $this->reason = null;
        $this->suppressed_at = null;
        $this->save();

        return $this;
    }

    /** @return Builder */
    public function scopeActive( Builder $query )
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** @return Builder */
    public function scopeSuppressed( Builder $query )
    {
        return $query->where('status', self::STATUS_SUPPRESSED);
    }
}
