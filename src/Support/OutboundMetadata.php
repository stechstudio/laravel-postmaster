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
     * Headers used purely as an in-process courier. Set by the relatedTo() /
     * forTenant() / storeContent() builders, then read and removed by
     * StashOutboundMetadata before the message is handed to the transport.
     */
    const HEADER_RELATED_TYPE   = 'X-Postmaster-Related-Type';
    const HEADER_RELATED_ID     = 'X-Postmaster-Related-Id';
    const HEADER_RECIPIENT_TYPE = 'X-Postmaster-Recipient-Type';
    const HEADER_RECIPIENT_ID   = 'X-Postmaster-Recipient-Id';
    // Per-address recipient model map (base64-encoded JSON of
    // {lowercased-address: [morph_class, key]}). Used when a Mailable's
    // Tracking declared a $recipients array for a multi-recipient send.
    const HEADER_RECIPIENT_MAP  = 'X-Postmaster-Recipient-Map';
    const HEADER_TENANT         = 'X-Postmaster-Tenant';
    const HEADER_STORE_CONTENT  = 'X-Postmaster-Store-Content';
    // Id of the EmailMessage this send is a resend of. Set by
    // Postmaster::resend() and by Mailables declaring resent_from on
    // their Tracking object. Written to the new row's resent_from_id
    // column for the dashboard's chain card.
    const HEADER_RESENT_FROM    = 'X-Postmaster-Resent-From';

    /** @var array<int, array<string, mixed>> */
    protected static array $pending = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public static function remember(int $objectId, array $attributes): void
    {
        static::$pending[$objectId] = $attributes;
    }

    /**
     * Retrieve and forget the metadata stashed for the given message.
     *
     * @return array<string, mixed>
     */
    public static function pull(int $objectId): array
    {
        $attributes = static::$pending[$objectId] ?? [];

        unset(static::$pending[$objectId]);

        return $attributes;
    }
}
