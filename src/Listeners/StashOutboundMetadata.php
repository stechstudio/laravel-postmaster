<?php

namespace STS\Postmaster\Listeners;

use Illuminate\Mail\Events\MessageSending;
use STS\Postmaster\Support\OutboundMetadata;

/**
 * Runs just before an email is handed to the transport. If the message
 * carries metadata headers (set via relatedTo() / forTenant()), the
 * values are moved into an in-process stash and the headers are stripped
 * so they never travel on the wire. RecordOutboundMessage then reads the
 * stash when MessageSent fires.
 */
class StashOutboundMetadata
{
    /**
     * Courier headers mapped to the stash keys they populate.
     */
    const HEADER_MAP = [
        OutboundMetadata::HEADER_RELATED_TYPE  => 'related_type',
        OutboundMetadata::HEADER_RELATED_ID    => 'related_id',
        OutboundMetadata::HEADER_TENANT        => 'tenant',
        OutboundMetadata::HEADER_TAGS          => 'tags',
        OutboundMetadata::HEADER_STORE_CONTENT => 'store_content',
    ];

    /**
     * @param MessageSending $event
     *
     * @return void
     */
    public function handle( MessageSending $event )
    {
        $headers = $event->message->getHeaders();
        $stashed = [];

        foreach (static::HEADER_MAP as $header => $key) {
            if (($value = $headers->get($header)) !== null) {
                $stashed[$key] = $value->getBodyAsString();
                $headers->remove($header);
            }
        }

        if ($stashed !== []) {
            OutboundMetadata::remember(spl_object_id($event->message), $stashed);
        }
    }
}
