<?php

namespace STS\EmailEvents\Listeners;

use Illuminate\Mail\Events\MessageSent;
use STS\EmailEvents\EmailEvent;
use STS\EmailEvents\EmailEvents;
use STS\EmailEvents\Listeners\Concerns\InteractsWithEmailMessages;
use STS\EmailEvents\Support\OutboundMetadata;

/**
 * Records every outbound email when persistence is enabled. The record is
 * later correlated to incoming webhook events by provider message id.
 */
class RecordOutboundMessage
{
    use InteractsWithEmailMessages;

    public function __construct( protected EmailEvents $events )
    {
    }

    /**
     * @param MessageSent $event
     *
     * @return void
     */
    public function handle( MessageSent $event )
    {
        $message = $event->message;
        $to = $message->getTo();

        $attributes = [
            'message_id' => $event->sent->getMessageId(),
            'recipient'  => $to ? $to[0]->getAddress() : null,
            'subject'    => $message->getSubject(),
            'status'     => EmailEvent::EVENT_SENT,
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

        $this->messageModel()->newQuery()->create($attributes);
    }
}
