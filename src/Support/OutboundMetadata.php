<?php

namespace STS\Postmaster\Support;

/**
 * In-process bridge that carries metadata about an outbound email — the
 * related model and/or the owning tenant — from the MessageSending event,
 * where it is read off the message headers and the headers are then
 * stripped, to the MessageSent event, where the email_messages row is
 * written.
 *
 * Keyed by the message object's identity (spl_object_id), which is stable
 * across both events within a single send and never travels on the wire,
 * so none of this metadata is ever exposed in the outbound email.
 */
class OutboundMetadata
{
    /**
     * Headers used purely as an in-process courier. Set by relatedTo() /
     * forTenant(), read and removed by StashOutboundMetadata before the
     * message is handed to the transport.
     */
    const HEADER_RELATED_TYPE = 'X-Postmaster-Related-Type';
    const HEADER_RELATED_ID   = 'X-Postmaster-Related-Id';
    const HEADER_TENANT       = 'X-Postmaster-Tenant';

    /**
     * @var array<int, array<string, string>>
     */
    protected static $pending = [];

    /**
     * @param int                   $objectId
     * @param array<string, string> $attributes
     *
     * @return void
     */
    public static function remember( $objectId, array $attributes )
    {
        static::$pending[$objectId] = $attributes;
    }

    /**
     * Retrieve and forget the metadata stashed for the given message.
     *
     * @param int $objectId
     *
     * @return array<string, string>
     */
    public static function pull( $objectId )
    {
        $attributes = static::$pending[$objectId] ?? [];

        unset(static::$pending[$objectId]);

        return $attributes;
    }
}
