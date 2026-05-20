<?php

namespace STS\EmailEvents\Support;

/**
 * In-process bridge that carries a related-model reference from the
 * MessageSending event — where it is read off the message headers and the
 * headers are then stripped — to the MessageSent event, where the
 * email_messages row is written.
 *
 * Keyed by the message object's identity (spl_object_id), which is stable
 * across both events within a single send and never travels on the wire,
 * so the related-model reference is never exposed in the outbound email.
 */
class RelatedModel
{
    /**
     * Headers used purely as an in-process courier. Set by the
     * TracksEmailEvents trait, read and removed by StashRelatedModel before
     * the message is handed to the transport.
     */
    const HEADER_TYPE = 'X-Email-Events-Related-Type';
    const HEADER_ID   = 'X-Email-Events-Related-Id';

    /**
     * @var array<int, array{type: string, id: string}>
     */
    protected static $pending = [];

    /**
     * @param int    $objectId
     * @param string $type
     * @param string $id
     *
     * @return void
     */
    public static function remember( $objectId, $type, $id )
    {
        static::$pending[$objectId] = ['type' => $type, 'id' => $id];
    }

    /**
     * Retrieve and forget the reference stashed for the given message.
     *
     * @param int $objectId
     *
     * @return array{type: string, id: string}|null
     */
    public static function pull( $objectId )
    {
        $related = static::$pending[$objectId] ?? null;

        unset(static::$pending[$objectId]);

        return $related;
    }
}
