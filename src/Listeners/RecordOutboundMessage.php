<?php

namespace STS\Postmaster\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSent;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Postmaster;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailAddresses;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailMessages;
use STS\Postmaster\Models\EmailAddress;
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

    public function __construct( protected Postmaster $events )
    {
    }

    /**
     * @param MessageSent $event
     *
     * @return void
     */
    public function handle( MessageSent $event )
    {
        $this->record($event->message, $event->sent->getMessageId(), $this->statusForCurrentTransport());
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
     * @param Email       $message
     * @param string|null $messageId Provider message id, or a synthetic id
     *                               for a message that was never sent.
     * @param string      $status    The lifecycle status to record.
     *
     * @return \Illuminate\Database\Eloquent\Model|null  The first row written
     *                                                  (the primary To row),
     *                                                  for callers that want
     *                                                  one to return.
     */
    public function record( Email $message, $messageId, $status = EmailEvent::STATUS_SENT )
    {
        $metadata = OutboundMetadata::pull(spl_object_id($message));
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

            $record = $this->messageModel()->newQuery()->create($row);

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
     * The columns every per-recipient row shares: provider id, subject,
     * status, sent_at, related model, tenant, tags, content (when storage
     * is on). Address-specific columns (to_address, recipient_role,
     * recipient_*) are added per row by record().
     *
     * @param Email                $message
     * @param string|null          $messageId
     * @param string               $status
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    protected function sharedAttributes( Email $message, $messageId, $status, array $metadata )
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

        // An explicit Mailable forTenant() wins; otherwise fall back to the
        // app-registered tenant resolver.
        $tenant = $metadata['tenant'] ?? $this->events->resolveTenant();

        if ($tenant !== null) {
            $attributes[$this->tenantColumn()] = $tenant;
        }

        // Per-message storeContent() / dontStoreContent() wins; otherwise
        // fall back to the store_content setting.
        $storeContent = isset($metadata['store_content'])
            ? $metadata['store_content'] === '1'
            : (bool) config('postmaster.persistence.store_content', false);

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
     * @param Email $message
     *
     * @return array<int, array{address: string, role: string}>
     */
    protected function envelope( Email $message )
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
     * @param array{address: string, role: string} $entry
     * @param array<string, mixed>                 $metadata
     * @param bool                                 $isPrimary
     *
     * @return array{type: string, id: int|string}|null
     */
    protected function recipientFor( array $entry, array $metadata, bool $isPrimary )
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
     * @param Email $message
     *
     * @return array<string, mixed>
     */
    protected function content( Email $message )
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
     * @param array<\Symfony\Component\Mime\Address> $addresses
     *
     * @return array<int, array{address: string, name: string}>
     */
    protected function addresses( array $addresses )
    {
        return array_map(fn ($address) => [
            'address' => $address->getAddress(),
            'name'    => $address->getName(),
        ], $addresses);
    }

    /**
     * The attachment filenames on the message — contents are never stored.
     *
     * @param Email $message
     *
     * @return array<int, string>
     */
    protected function attachments( Email $message )
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
     *
     * @return string|null
     */
    protected function body( $body )
    {
        if (is_resource($body)) {
            rewind($body);

            return stream_get_contents($body) ?: null;
        }

        return is_string($body) ? $body : null;
    }
}
