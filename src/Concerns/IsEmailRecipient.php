<?php

namespace STS\Postmaster\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use STS\Postmaster\Models\EmailMessage;

/**
 * Add to any model the emails were sent *to* — typically a User. Gives the
 * model an `emailMessages` relationship listing every recorded email sent to
 * it, regardless of what business record the email was about. The link is
 * populated from a Mailable's Tracking(recipient: ...) declaration or from
 * the global Postmaster::resolveRecipientUsing() resolver.
 *
 * For the model the emails were *about* (an Order, an Invoice), use
 * HasEmailMessages instead — same shape, different polymorphic key.
 *
 * Requires the optional persistence layer (POSTMASTER_PERSISTENCE=true).
 */
trait IsEmailRecipient
{
    /**
     * The email delivery records sent to this model.
     *
     * @return MorphMany
     */
    public function emailMessages()
    {
        return $this->morphMany(
            config('postmaster.persistence.model', EmailMessage::class),
            'recipient'
        );
    }

    /**
     * The most recent recorded email sent to this model, if any.
     *
     * @return EmailMessage|null
     */
    public function latestEmailMessage()
    {
        return $this->emailMessages()->latest('id')->first();
    }

    /**
     * Whether the most recent email sent to this model failed to reach them
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
