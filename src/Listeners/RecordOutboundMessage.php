<?php

namespace STS\Postmaster\Listeners;

use Illuminate\Mail\Events\MessageSent;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Postmaster;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailAddresses;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailMessages;
use STS\Postmaster\Support\OutboundMetadata;
use Symfony\Component\Mime\Email;

/**
 * Records every outbound email when persistence is enabled. The record is
 * later correlated to incoming webhook events by provider message id.
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
        $this->record($event->message, $event->sent->getMessageId(), EmailEvent::EVENT_SENT);
    }

    /**
     * Write the email_messages row for an outbound message. Shared by the
     * normal send path (MessageSent) and sandbox delivery, which records the
     * message before suppressing the send.
     *
     * @param Email       $message
     * @param string|null $messageId Provider message id, or a synthetic id for
     *                               a sandboxed message that was never sent.
     * @param string      $status    The lifecycle status to record.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function record( Email $message, $messageId, $status = EmailEvent::EVENT_SENT )
    {
        $to = $message->getTo();

        $attributes = [
            'message_id' => $messageId,
            'recipient'  => $to ? $to[0]->getAddress() : null,
            'subject'    => $message->getSubject(),
            'status'     => $status,
            'sent_at'    => now(),
        ];

        $metadata = OutboundMetadata::pull(spl_object_id($message));

        if (isset($metadata['related_type'], $metadata['related_id'])) {
            $attributes['related_type'] = $metadata['related_type'];
            $attributes['related_id']   = $metadata['related_id'];
        }

        // An explicit Mailable forTenant() wins; otherwise fall back to the
        // app-registered tenant resolver.
        $tenant = $metadata['tenant'] ?? $this->events->resolveTenant();

        if ($tenant !== null) {
            $attributes[$this->tenantColumn()] = $tenant;
        }

        if (config('postmaster.persistence.store_content', false)) {
            $attributes += $this->content($message);
        }

        $record = $this->messageModel()->newQuery()->create($attributes);

        // Seed the timeline with the send itself, so the history is complete
        // rather than starting at the first webhook event.
        $this->recordEvent($record, [
            'status'      => $status,
            'occurred_at' => $attributes['sent_at'],
        ]);

        // Note the recipient so the address is on record as one we send to.
        $this->touchAddress($attributes['recipient']);

        return $record;
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
