<?php

namespace STS\Postmaster\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use STS\Postmaster\Models\EmailMessage;

/**
 * Add to any model the emails are *about* — an Order, an Invoice, a Project.
 * Gives the model an `emailMessages` relationship listing every recorded
 * email associated with it via a Mailable's relatedTo() / Tracking(related:)
 * declaration.
 *
 * For the model the emails were sent *to* (typically a User), use
 * IsEmailRecipient instead — same shape, different polymorphic key.
 *
 * Requires the optional persistence layer (POSTMASTER_PERSISTENCE=true).
 */
trait HasEmailMessages
{
    /**
     * The email delivery records associated with this model.
     */
    public function emailMessages(): MorphMany
    {
        return $this->morphMany(
            config('postmaster.persistence.model', EmailMessage::class),
            'related'
        );
    }

    /**
     * The most recent recorded email for this model, if any.
     */
    public function latestEmailMessage(): ?EmailMessage
    {
        return $this->emailMessages()->latest('id')->first();
    }

    /**
     * Whether this model's most recent email failed to reach the recipient
     * (bounced, dropped, or complained). False when nothing is recorded yet.
     */
    public function emailDeliveryFailed(): bool
    {
        return $this->latestEmailMessage()?->isFailed() ?? false;
    }
}
