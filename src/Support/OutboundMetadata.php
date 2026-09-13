<?php

namespace STS\Postmaster\Support;

use Symfony\Component\Mime\Email;
use WeakMap;

/**
 * In-process bridge that carries metadata about an outbound email — the
 * related model and/or the owning tenant — from the MessageSending event,
 * where it is read off the message headers and the headers are then
 * stripped, to the MessageSent event, where the email_messages row is
 * written.
 *
 * Uses weak references so failed sends do not leak metadata into later sends.
 * The standard Message-ID matches transport clones; tracking data stays local.
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
    // Whether to keep this message's attachment bytes. Independent of
    // HEADER_STORE_CONTENT: an invoice can be worth keeping when the body
    // carrying a magic-login link is not.
    const HEADER_STORE_ATTACHMENTS = 'X-Postmaster-Store-Attachments';
    // Id of the EmailMessage this send is a resend of. Set by
    // Postmaster::resend() and by Mailables declaring resent_from on
    // their Tracking object. Written to the new row's resent_from_id
    // column for the dashboard's chain card.
    const HEADER_RESENT_FROM    = 'X-Postmaster-Resent-From';

    /** @var array<int, array<string, mixed>> */
    protected static array $pending = [];

    /** @var WeakMap<Email, array<string, mixed>>|null */
    protected static ?WeakMap $messages = null;

    /**
     * The id of the sandboxed EmailMessage currently being released, or null.
     *
     * A release is a synchronous Mail::send() — so rather than carry a marker
     * on the message and match it across the MessageSending/MessageSent events
     * by object identity (fragile: real provider transports can hand the two
     * events different message instances, dropping the marker), Postmaster::
     * release() simply sets this flag for the duration of the send. Both
     * InterceptSandboxMail (bypass the sandbox) and RecordOutboundMessage
     * (reconcile the existing row instead of writing a new one) read it
     * directly. It cannot be lost in transit.
     */
    protected static ?int $releasing = null;

    /**
     * @param array<string, mixed> $attributes
     */
    public static function remember(Email|int $message, array $attributes): void
    {
        if (is_int($message)) {
            static::$pending[$message] = $attributes;
            return;
        }
        if (! $message->getHeaders()->has('Message-ID')) {
            $message->getHeaders()->addIdHeader('Message-ID', $message->generateMessageId());
        }
        static::$messages ??= new WeakMap;
        static::$messages[$message] = $attributes;
    }

    /**
     * Retrieve and forget metadata, including when the transport cloned the email.
     *
     * @return array<string, mixed>
     */
    public static function pull(Email|int $message): array
    {
        if (is_int($message)) {
            $attributes = static::$pending[$message] ?? [];
            unset(static::$pending[$message]);
            return $attributes;
        }
        $id = $message->getHeaders()->get('Message-ID')?->getBodyAsString();
        foreach (static::$messages ?? [] as $original => $attributes) {
            if ($original === $message || ($id !== null && $id === $original->getHeaders()->get('Message-ID')?->getBodyAsString())) {
                unset(static::$messages[$original]);
                return $attributes;
            }
        }
        return [];
    }

    /**
     * Mark (or clear) the sandboxed message being released right now.
     */
    public static function setReleasing(?int $messageId): void
    {
        static::$releasing = $messageId;
    }

    /**
     * The id of the sandboxed message being released in the current send, or
     * null when this is an ordinary send.
     */
    public static function releasing(): ?int
    {
        return static::$releasing;
    }
}
