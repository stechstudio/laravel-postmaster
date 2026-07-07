<?php

namespace STS\Postmaster\Listeners;

use Illuminate\Mail\Events\MessageSent;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Postmaster;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailAddresses;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailMessages;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Support\OutboundMetadata;
use Symfony\Component\Mime\Email;

/**
 * Records every outbound email when persistence is enabled. One row per
 * envelope recipient (To, Cc, Bcc) — all sharing the same provider message
 * id — so each address has its own delivery state and webhooks correlate
 * cleanly to the right row.
 */
class RecordOutboundMessage
{
    use InteractsWithEmailAddresses;
    use InteractsWithEmailMessages;

    public function __construct(protected Postmaster $events)
    {
    }

    public function handle(MessageSent $event): void
    {
        $this->record($event->message, $this->resolveProviderMessageId($event), $this->statusForCurrentTransport());
    }

    /**
     * The provider's id for this outbound message — the same id their
     * webhooks will correlate on. Most Symfony mailer transports set the
     * SentMessage's id from the provider's API response, so
     * `$event->sent->getMessageId()` is the default. Two of Laravel's
     * homegrown transports (ResendTransport, SesTransport) stamp the
     * provider id on the email as a header instead and leave the
     * SentMessage with Symfony's auto-generated id; check those headers
     * first so correlation lands on the right row.
     *
     * @var array<int, string> Headers to prefer, in order. First match wins.
     */
    protected const PROVIDER_MESSAGE_ID_HEADERS = [
        'X-Resend-Email-ID',   // Laravel's ResendTransport
        'X-SES-Message-ID',    // Laravel's SesTransport
    ];

    protected function resolveProviderMessageId(MessageSent $event): ?string
    {
        $headers = $event->message->getHeaders();

        foreach (self::PROVIDER_MESSAGE_ID_HEADERS as $name) {
            if ($header = $headers->get($name)) {
                return $header->getBodyAsString();
            }
        }

        // Symfony's Mailgun transport returns the Message-ID with angle
        // brackets straight from the Mailgun API; the webhook payload
        // delivers it without brackets. Strip them so correlation matches.
        // No-op for the other providers (their ids never have brackets).
        // (For SMTP sends, Symfony's transport returns the queue id from
        // the 250 OK response, which matches the prefix of SendGrid's
        // sg_message_id — the SendGrid adapter handles that side.)
        return trim((string) $event->sent->getMessageId(), '<>');
    }

    /**
     * The lifecycle status to record for a send completing right now. Usually
     * STATUS_SENT — but when the default mailer's transport is Laravel's `log`
     * or `array` driver, there's no real delivery and no webhook will ever
     * land, so the row is marked terminal so the dashboard doesn't keep it in
     * a "waiting on the provider" state forever.
     *
     * Detection is best-effort and reads the default mailer's transport from
     * config. A per-call mailer override (e.g. Mail::mailer('log')->send())
     * while a different default is configured will fall through to
     * STATUS_SENT — same as the previous behavior, no regression.
     */
    protected function statusForCurrentTransport(): string
    {
        $default   = config('mail.default');
        $transport = config("mail.mailers.{$default}.transport", $default);

        return match ($transport) {
            'log'   => EmailEvent::STATUS_LOGGED,
            'array' => EmailEvent::STATUS_CAPTURED,
            default => EmailEvent::STATUS_SENT,
        };
    }

    /**
     * Write the email_messages row(s) for an outbound message — one per
     * envelope recipient. Shared by the normal send path (MessageSent) and
     * the sandbox / suppression interceptors, which record before suppressing
     * the actual send.
     *
     * Returns the first row written (the primary To row), for callers that
     * want one to return. $messageId may be a synthetic id for a message
     * that was never sent.
     */
    public function record(Email $message, ?string $messageId, string $status = EmailEvent::STATUS_SENT): ?EmailMessage
    {
        $metadata = OutboundMetadata::pull(spl_object_id($message));

        // A release of a previously sandboxed message: the send just went out
        // for real, so reconcile the original row(s) instead of writing new
        // ones. Postmaster::release() sets this flag for the duration of the
        // send, so it's reliable regardless of how the transport handles the
        // message.
        if (($releaseOf = OutboundMetadata::releasing()) !== null) {
            return $this->reconcileRelease($releaseOf, $messageId, $status);
        }

        $shared   = $this->sharedAttributes($message, $messageId, $status, $metadata);
        $envelope = $this->envelope($message);

        $primary = null;

        foreach ($envelope as $entry) {
            $row = $shared + [
                'to_address'     => $entry['address'],
                'recipient_role' => $entry['role'],
            ];

            // Per-address recipient model: a declared map wins; the singular
            // declaration applies only to the primary To row; resolver fills
            // any remaining gap.
            if ($model = $this->recipientFor($entry, $metadata, $primary === null)) {
                $row['recipient_type'] = $model['type'];
                $row['recipient_id']   = $model['id'];
            }

            $record = EmailMessage::model()->newQuery()->create($row);

            // Seed the timeline with the send itself, so the history is
            // complete rather than starting at the first webhook event.
            $this->recordActivity($record, [
                'status'      => $status,
                'occurred_at' => $shared['sent_at'],
            ]);

            // Note the address so it's on record as one we send to.
            $this->touchAddress($entry['address']);

            $primary = $primary ?? $record;
        }

        return $primary;
    }

    /**
     * Reconcile a released sandbox message. The email just went out for real,
     * so swap the synthetic sandbox id on the original row(s) for the real
     * provider message id (webhooks will now correlate), flip them from
     * "sandboxed" to their true sent status, refresh sent_at, and log the
     * release on the timeline. Every envelope-sibling row (To/Cc/Bcc) that
     * shared the sandbox id transitions together.
     */
    protected function reconcileRelease(int $originalId, ?string $messageId, string $status): ?EmailMessage
    {
        $original = EmailMessage::model()->newQuery()->withoutGlobalScopes()->find($originalId);

        if ($original === null) {
            return null;
        }

        $sentAt  = now();
        $primary = null;

        // The sandbox recorder wrote one row per envelope recipient, all
        // sharing the synthetic sandbox id — move the whole set together.
        $rows = EmailMessage::model()->newQuery()->withoutGlobalScopes()
            ->where('provider_message_id', $original->provider_message_id)
            ->get();

        foreach ($rows as $row) {
            $row->forceFill([
                'provider_message_id' => $messageId,
                'status'              => $status,
                'sent_at'             => $sentAt,
            ])->save();

            $this->recordActivity($row, [
                'status'      => $status,
                'reason'      => 'released from sandbox',
                'occurred_at' => $sentAt,
            ]);

            $primary = $primary ?? $row;
        }

        return $primary;
    }

    /**
     * The columns every per-recipient row shares: provider id, subject,
     * status, sent_at, related model, tenant, tags, content (when storage
     * is on). Address-specific columns (to_address, recipient_role,
     * recipient_*) are added per row by record().
     *
     * @param  array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function sharedAttributes(Email $message, ?string $messageId, string $status, array $metadata): array
    {
        $attributes = [
            'provider_message_id' => $messageId,
            'subject'             => $message->getSubject(),
            'status'              => $status,
            'sent_at'             => now(),
        ];

        if (isset($metadata['related_type'], $metadata['related_id'])) {
            $attributes['related_type'] = $metadata['related_type'];
            $attributes['related_id']   = $metadata['related_id'];
        }

        if (! empty($metadata['tags'])) {
            $attributes['tags'] = $metadata['tags'];
        }

        // Link this row back to the original when the send is a resend —
        // populates the FK that EmailMessage::resentFrom() / resends() /
        // resendChain() use, and what the dashboard's chain card walks.
        if (isset($metadata['resent_from'])) {
            $attributes['resent_from_id'] = (int) $metadata['resent_from'];
        }

        // An explicit Mailable forTenant() wins; otherwise fall back to the
        // app-registered tenant resolver.
        $tenant = $metadata['tenant'] ?? $this->events->resolveTenant();

        if ($tenant !== null) {
            $attributes[EmailMessage::tenantColumn()] = $tenant;
        }

        // Per-message storeContent() / dontStoreContent() wins; then the
        // app-registered storeContentWhen() resolver; then the store_content
        // setting. (resolveStoreContent() returns null when no resolver is
        // registered, so the config flag is the final fallback.)
        $storeContent = isset($metadata['store_content'])
            ? $metadata['store_content'] === '1'
            : ($this->events->resolveStoreContent($message)
                ?? (bool) config('postmaster.persistence.store_content', false));

        if ($storeContent) {
            $attributes += $this->content($message);
        }

        return $attributes;
    }

    /**
     * The envelope recipients of this message — To, Cc, Bcc, in that order —
     * with their roles tagged. Addresses are lowercased on the way in to
     * keep correlation case-insensitive.
     *
     * @return array<int, array{address: string, role: string}>
     */
    protected function envelope(Email $message): array
    {
        $entries = [];

        foreach (['to' => $message->getTo(), 'cc' => $message->getCc(), 'bcc' => $message->getBcc()] as $role => $addresses) {
            foreach ($addresses as $address) {
                $entries[] = [
                    'address' => EmailAddress::normalize($address->getAddress()),
                    'role'    => $role,
                ];
            }
        }

        // Some intercepted sends (sandboxed, blocked) won't have an envelope
        // we can dig out of the Symfony message after the fact — record at
        // least one row for the primary recipient so the dashboard sees it.
        if (empty($entries) && $primary = ($message->getTo()[0] ?? null)) {
            $entries[] = ['address' => EmailAddress::normalize($primary->getAddress()), 'role' => 'to'];
        }

        return $entries;
    }

    /**
     * Resolve which recipient model (if any) to attach to this row.
     *
     * Precedence: the per-address map from Tracking(recipients: [...])
     * wins; for the primary To row only, the singular Tracking(recipient:)
     * declaration applies; then the app-registered resolver fills any
     * remaining gap.
     *
     * @param  array{address: string, role: string} $entry
     * @param  array<string, mixed>                 $metadata
     * @return array{type: string, id: int|string}|null
     */
    protected function recipientFor(array $entry, array $metadata, bool $isPrimary): ?array
    {
        $map = $metadata['recipient_map'] ?? [];

        if (isset($map[$entry['address']])) {
            return ['type' => $map[$entry['address']][0], 'id' => $map[$entry['address']][1]];
        }

        if ($isPrimary && isset($metadata['recipient_type'], $metadata['recipient_id'])) {
            return ['type' => $metadata['recipient_type'], 'id' => $metadata['recipient_id']];
        }

        if ($model = $this->events->resolveRecipient($entry['address'])) {
            return ['type' => $model->getMorphClass(), 'id' => $model->getKey()];
        }

        return null;
    }

    /**
     * A full representation of the message — sender, recipients, bodies, and
     * attachment filenames (never attachment contents).
     *
     * @return array<string, mixed>
     */
    protected function content(Email $message): array
    {
        $from = $message->getFrom();

        return [
            'from_address' => $from ? $from[0]->getAddress() : null,
            'recipients'   => [
                'to'  => $this->addresses($message->getTo()),
                'cc'  => $this->addresses($message->getCc()),
                'bcc' => $this->addresses($message->getBcc()),
            ],
            'html_body'    => $this->body($message->getHtmlBody()),
            'text_body'    => $this->body($message->getTextBody()),
            'attachments'  => $this->attachments($message),
        ];
    }

    /**
     * @param  array<\Symfony\Component\Mime\Address> $addresses
     * @return array<int, array{address: string, name: string}>
     */
    protected function addresses(array $addresses): array
    {
        return array_map(fn ($address) => [
            'address' => $address->getAddress(),
            'name'    => $address->getName(),
        ], $addresses);
    }

    /**
     * The attachment filenames on the message — contents are never stored.
     *
     * @return array<int, string>
     */
    protected function attachments(Email $message): array
    {
        $names = array_map(
            fn ($attachment) => $attachment->getFilename(),
            $message->getAttachments()
        );

        return array_values(array_filter($names));
    }

    /**
     * Normalize a Symfony message body, which may be a string or a stream.
     *
     * @param resource|string|null $body
     */
    protected function body($body): ?string
    {
        if (is_resource($body)) {
            rewind($body);

            return stream_get_contents($body) ?: null;
        }

        return is_string($body) ? $body : null;
    }
}
