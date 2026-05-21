<?php

namespace STS\Postmaster\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use STS\Postmaster\Models\EmailMessage;

/**
 * Add to any model whose emails you want to track (an Order, User, etc.).
 * Gives the model an `emailMessages` relationship listing every recorded
 * email associated with it via a Mailable's relatedTo() call.
 *
 * Requires the optional persistence layer (POSTMASTER_PERSISTENCE=true).
 */
trait HasEmailMessages
{
    /**
     * The email delivery records associated with this model.
     *
     * @return MorphMany
     */
    public function emailMessages()
    {
        return $this->morphMany(
            config('postmaster.persistence.model', EmailMessage::class),
            'related'
        );
    }

    /**
     * The most recent recorded email for this model, if any.
     *
     * @return EmailMessage|null
     */
    public function latestEmailMessage()
    {
        return $this->emailMessages()->latest('id')->first();
    }

    /**
     * Whether this model's most recent email failed to reach the recipient
     * (bounced, dropped, or complained). False when nothing is recorded yet.
     *
     * @return bool
     */
    public function emailDeliveryFailed()
    {
        $latest = $this->latestEmailMessage();

        return $latest !== null
            && in_array($latest->getAttribute('status'), EmailMessage::FAILED_STATUSES, true);
    }
}
