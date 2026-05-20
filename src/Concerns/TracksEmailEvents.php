<?php

namespace STS\EmailEvents\Concerns;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Mime\Email;
use STS\EmailEvents\Support\RelatedModel;

/**
 * Add to a Mailable to associate the email with one of your models (an
 * Order, User, etc.). When persistence is enabled, the recorded
 * email_messages row is linked back to that model via a polymorphic
 * relationship, so a model can list its own delivery history.
 *
 * The association is carried on the message only in-process: it is written
 * as a header, then read and stripped before the email is transmitted, so
 * nothing about the related model is ever exposed in the outbound email.
 *
 * Requires the optional persistence layer (MAIL_EVENTS_PERSISTENCE=true).
 */
trait TracksEmailEvents
{
    /**
     * Associate this email with the given model.
     *
     * @param Model $model
     *
     * @return $this
     */
    public function relatedTo( Model $model )
    {
        return $this->withSymfonyMessage(function (Email $message) use ($model) {
            $message->getHeaders()->addTextHeader(
                RelatedModel::HEADER_TYPE, $model->getMorphClass()
            );

            $message->getHeaders()->addTextHeader(
                RelatedModel::HEADER_ID, (string) $model->getKey()
            );
        });
    }
}
