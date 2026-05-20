<?php

namespace STS\EmailEvents\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use STS\EmailEvents\Models\EmailMessage;

/**
 * Add to any model whose emails you want to track (an Order, User, etc.).
 * Gives the model an `emailMessages` relationship listing every recorded
 * email associated with it via a Mailable's relatedTo() call.
 *
 * Requires the optional persistence layer (MAIL_EVENTS_PERSISTENCE=true).
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
            config('email-events.persistence.model', EmailMessage::class),
            'related'
        );
    }
}
