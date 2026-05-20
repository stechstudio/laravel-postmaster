<?php

namespace STS\EmailEvents\Listeners;

use Illuminate\Mail\Events\MessageSent;
use STS\EmailEvents\EmailEvent;
use STS\EmailEvents\Listeners\Concerns\InteractsWithEmailMessages;
use STS\EmailEvents\Support\RelatedModel;

/**
 * Records every outbound email when persistence is enabled. The record is
 * later correlated to incoming webhook events by provider message id.
 */
class RecordOutboundMessage
{
    use InteractsWithEmailMessages;

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

        if ($related = RelatedModel::pull(spl_object_id($message))) {
            $attributes['related_type'] = $related['type'];
            $attributes['related_id']   = $related['id'];
        }

        $this->messageModel()->newQuery()->create($attributes);
    }
}
