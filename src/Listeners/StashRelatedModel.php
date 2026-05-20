<?php

namespace STS\EmailEvents\Listeners;

use Illuminate\Mail\Events\MessageSending;
use STS\EmailEvents\Support\RelatedModel;

/**
 * Runs just before an email is handed to the transport. If the message
 * carries related-model headers (set via the TracksEmailEvents trait), the
 * reference is moved into an in-process stash and the headers are stripped
 * so they never travel on the wire. RecordOutboundMessage then reads the
 * stash when MessageSent fires.
 */
class StashRelatedModel
{
    /**
     * @param MessageSending $event
     *
     * @return void
     */
    public function handle( MessageSending $event )
    {
        $headers = $event->message->getHeaders();

        $type = $headers->get(RelatedModel::HEADER_TYPE);
        $id   = $headers->get(RelatedModel::HEADER_ID);

        if ($type === null || $id === null) {
            return;
        }

        RelatedModel::remember(
            spl_object_id($event->message),
            $type->getBodyAsString(),
            $id->getBodyAsString()
        );

        $headers->remove(RelatedModel::HEADER_TYPE);
        $headers->remove(RelatedModel::HEADER_ID);
    }
}
