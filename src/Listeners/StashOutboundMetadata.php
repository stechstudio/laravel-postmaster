<?php

namespace STS\Postmaster\Listeners;

use Illuminate\Mail\Events\MessageSending;
use STS\Postmaster\Support\OutboundMetadata;

/**
 * Runs just before an email is handed to the transport. The package's own
 * courier headers (related model, tenant, content preference) are moved into
 * an in-process stash and stripped so they never travel on the wire. The
 * message's tags are also stashed, but left in place — the transport forwards
 * them to the provider. RecordOutboundMessage reads the stash when
 * MessageSent fires.
 */
class StashOutboundMetadata
{
    /**
     * Courier headers mapped to the stash keys they populate.
     */
    const HEADER_MAP = [
        OutboundMetadata::HEADER_RELATED_TYPE   => 'related_type',
        OutboundMetadata::HEADER_RELATED_ID     => 'related_id',
        OutboundMetadata::HEADER_RECIPIENT_TYPE => 'recipient_type',
        OutboundMetadata::HEADER_RECIPIENT_ID   => 'recipient_id',
        OutboundMetadata::HEADER_TENANT         => 'tenant',
        OutboundMetadata::HEADER_STORE_CONTENT  => 'store_content',
        OutboundMetadata::HEADER_RESENT_FROM    => 'resent_from',
        OutboundMetadata::HEADER_RELEASE_OF     => 'release_of',
    ];

    public function handle(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();
        $stashed = [];

        foreach (static::HEADER_MAP as $header => $key) {
            if (($value = $headers->get($header)) !== null) {
                $stashed[$key] = $value->getBodyAsString();
                $headers->remove($header);
            }
        }

        // The recipient map ships as a single base64-encoded JSON header —
        // one header instead of a pair per address — decoded here so the
        // recorder can read it as a normal PHP array.
        if (($value = $headers->get(OutboundMetadata::HEADER_RECIPIENT_MAP)) !== null) {
            $decoded = json_decode(base64_decode($value->getBodyAsString()), true);
            if (is_array($decoded)) {
                $stashed['recipient_map'] = $decoded;
            }
            $headers->remove(OutboundMetadata::HEADER_RECIPIENT_MAP);
        }

        // Laravel renders a Mailable's / notification's tags as Symfony
        // X-Tag headers. Read them so they land on the record, but leave
        // them in place — the provider transport forwards them.
        $tags = [];

        foreach ($headers->all('x-tag') as $tag) {
            $tags[] = $tag->getBodyAsString();
        }

        if ($tags !== []) {
            $stashed['tags'] = $tags;
        }

        if ($stashed !== []) {
            OutboundMetadata::remember(spl_object_id($event->message), $stashed);
        }
    }
}
