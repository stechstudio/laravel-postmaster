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
}
