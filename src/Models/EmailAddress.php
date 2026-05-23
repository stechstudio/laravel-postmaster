<?php

namespace STS\Postmaster\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The current deliverability status of a single recipient address.
 *
 * This is the third and most collapsed projection: email_message_events roll
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
        'suppressed_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

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
