<?php

namespace STS\Postmaster\Listeners;

use STS\Postmaster\EmailEvent;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailMessages;

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

        // Webhooks arrive with no tenant context, so the correlation lookup
        // must ignore any tenant (or other) global scope a swapped-in model
        // may carry — otherwise the update silently misses every row.
        $record = $this->messageModel()->newQuery()
            ->withoutGlobalScopes()
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
