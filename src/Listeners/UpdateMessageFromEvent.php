<?php

namespace STS\Postmaster\Listeners;

use STS\Postmaster\EmailEvent;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailAddresses;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailMessages;
use STS\Postmaster\Models\EmailMessage;

/**
 * Keeps the stored email record current as webhook events arrive, correlated
 * by provider message id.
 *
 * Each event refreshes the email_messages summary row (its latest status) and,
 * when timeline recording is enabled, is also appended to email_message_events
 * so the message keeps its full delivery history. If no summary record exists
 * (e.g. the email was sent before persistence was enabled) one is created.
 */
class UpdateMessageFromEvent
{
    use InteractsWithEmailAddresses;
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

        $record = $this->findOrCreateMessage($event, $messageId);

        // Hand the correlated record to the event so any later listener can
        // reach the originating message — and, via related(), the app model
        // behind it — without repeating the message-id lookup.
        $event->emailMessage = $record;

        $this->refreshSummary($record, $event);

        $this->applyEventToAddress($event);

        $this->recordEvent($record, [
            'provider'    => $event->getProvider(),
            'status'      => $event->getAction(),
            'bounce_type' => $event->getBounceType(),
            'response'    => $this->flatten($event->getResponse()),
            'reason'      => $this->flatten($event->getReason()),
            'code'        => $this->flatten($event->getCode()),
            'url'         => $event->getUrl(),
            'occurred_at' => $event->getDate() ?? now(),
        ]);
    }

    /**
     * Locate the summary record for this message id, creating a minimal one
     * if the email was never recorded at send time.
     *
     * The correlation lookup ignores global scopes: webhooks arrive with no
     * tenant context, so a tenant (or other) global scope on a swapped-in
     * model would otherwise hide every row.
     *
     * @param EmailEvent $event
     * @param string     $messageId
     *
     * @return EmailMessage
     */
    protected function findOrCreateMessage( EmailEvent $event, $messageId )
    {
        $record = $this->messageModel()->newQuery()
            ->withoutGlobalScopes()
            ->where('provider_message_id', $messageId)
            ->latest('id')
            ->first();

        if ($record) {
            return $record;
        }

        return $this->messageModel()->newQuery()->create([
            'provider_message_id' => $messageId,
            'provider'            => $event->getProvider(),
            'to_address'          => $event->getRecipient(),
        ]);
    }

    /**
     * Update the summary row's latest status. The status only advances when
     * this event is the newest one seen — webhooks can arrive out of order,
     * and a late delivery webhook must not overwrite a later bounce.
     *
     * @param EmailMessage $record
     * @param EmailEvent   $event
     *
     * @return void
     */
    protected function refreshSummary( EmailMessage $record, EmailEvent $event )
    {
        if (empty($record->provider)) {
            $record->provider = $event->getProvider();
        }

        if (empty($record->to_address)) {
            $record->to_address = $event->getRecipient();
        }

        $occurredAt = $event->getDate() ?? now();

        if ($record->last_event_at === null || $occurredAt >= $record->last_event_at) {
            $record->status = $event->getAction();
            $record->last_event_at = $occurredAt;

            if ($event->getBounceType() !== null) {
                $record->bounce_type = $event->getBounceType();
            }
        }

        if ($record->isDirty()) {
            $record->save();
        }
    }

    /**
     * Reduce a provider's diagnostic value to something storable in a text
     * column — most are already strings; arrays are JSON-encoded.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    protected function flatten( $value )
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
