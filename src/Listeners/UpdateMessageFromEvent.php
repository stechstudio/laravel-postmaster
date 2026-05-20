<?php

namespace STS\EmailEvents\Listeners;

use STS\EmailEvents\EmailEvent;
use STS\EmailEvents\Listeners\Concerns\InteractsWithEmailMessages;

/**
 * Updates the stored email record as webhook events arrive, correlated by
 * provider message id. If no record exists (e.g. the email was sent before
 * persistence was enabled) a record is created from the event so the table
 * still reflects the full delivery history.
 */
class UpdateMessageFromEvent
{
    use InteractsWithEmailMessages;

    /**
     * @param EmailEvent $event
     *
     * @return void
     */
    public function handle( EmailEvent $event )
    {
        $messageId = $event->getMessageId();

        if (empty($messageId)) {
            return;
        }

        $attributes = [
            'provider'      => $event->getProvider(),
            'status'        => $event->getAction(),
            'last_event_at' => $event->getDate() ?? now(),
        ];

        if ($event->getBounceType() !== null) {
            $attributes['bounce_type'] = $event->getBounceType();
        }

        $record = $this->messageModel()->newQuery()
            ->where('message_id', $messageId)
            ->latest('id')
            ->first();

        if ($record) {
            if (empty($record->recipient)) {
                $attributes['recipient'] = $event->getRecipient();
            }

            $record->fill($attributes)->save();

            return;
        }

        $this->messageModel()->newQuery()->create($attributes + [
            'message_id' => $messageId,
            'recipient'  => $event->getRecipient(),
        ]);
    }
}
