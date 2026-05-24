<?php

namespace STS\Postmaster\Listeners;

use STS\Postmaster\EmailEvent;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailAddresses;
use STS\Postmaster\Listeners\Concerns\InteractsWithEmailMessages;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;

/**
 * Keeps the stored email record current as webhook events arrive, correlated
 * by provider message id.
 *
 * Each event refreshes the email_messages summary row (its latest status) and,
 * when timeline recording is enabled, is also appended to email_activity
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
        $messageId = $event->providerMessageId();

        if (empty($messageId)) {
            return;
        }

        $address = $event->toAddress() === null ? null : EmailAddress::normalize($event->toAddress());
        $record  = $this->findOrCreateMessage($event, $messageId, $address);

        // Hand the correlated record to the event so any later listener can
        // reach the originating message — and, via related(), the app model
        // behind it — without repeating the message-id lookup.
        $event->setEmailMessage($record);

        $this->refreshSummary($record, $event);

        $this->applyEventToAddress($event);

        $this->recordActivity($record, [
            'provider'    => $event->provider(),
            'status'      => $event->status(),
            'bounce_type' => $event->bounceType(),
            'response'    => $this->flatten($event->response()),
            'reason'      => $this->flatten($event->reason()),
            'code'        => $this->flatten($event->code()),
            'url'         => $event->clickedUrl(),
            'occurred_at' => $event->occurredAt() ?? now(),
        ]);
    }

    /**
     * Locate the per-recipient summary row for this event, creating a
     * minimal one if the email was never recorded at send time (or if this
     * is a webhook for a Cc/Bcc address we didn't pre-record).
     *
     * The correlation key is (provider_message_id, to_address), both
     * lowercased — one outbound submission produces one row per envelope
     * recipient, all sharing the provider id, distinguished by address.
     *
     * The lookup ignores global scopes: webhooks arrive with no tenant
     * context, so a tenant (or other) global scope on a swapped-in model
     * would otherwise hide every row.
     *
     * @param EmailEvent  $event
     * @param string      $messageId
     * @param string|null $address    Lowercased recipient address from the event.
     *
     * @return EmailMessage
     */
    protected function findOrCreateMessage( EmailEvent $event, $messageId, $address )
    {
        $query = $this->messageModel()->newQuery()
            ->withoutGlobalScopes()
            ->where('provider_message_id', $messageId);

        if ($address !== null) {
            $query->where('to_address', $address);
        }

        if ($record = $query->latest('id')->first()) {
            return $record;
        }

        return $this->messageModel()->newQuery()->create([
            'provider_message_id' => $messageId,
            'provider'            => $event->provider(),
            'to_address'          => $address,
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
            $record->provider = $event->provider();
        }

        if (empty($record->to_address) && $event->toAddress() !== null) {
            $record->to_address = EmailAddress::normalize($event->toAddress());
        }

        $occurredAt = $event->occurredAt() ?? now();

        if ($record->last_event_at === null || $occurredAt >= $record->last_event_at) {
            $record->status = $event->status();
            $record->last_event_at = $occurredAt;

            if ($event->bounceType() !== null) {
                $record->bounce_type = $event->bounceType();
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
