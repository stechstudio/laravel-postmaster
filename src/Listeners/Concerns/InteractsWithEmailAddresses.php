<?php

namespace STS\Postmaster\Listeners\Concerns;

use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailAddress;

trait InteractsWithEmailAddresses
{
    /**
     * A fresh instance of the configured (swappable) email address model.
     *
     * @return EmailAddress
     */
    protected function addressModel()
    {
        $class = config('postmaster.persistence.address_model', EmailAddress::class);

        return new $class;
    }

    /**
     * Record that we sent to an address. Creates the row (active) if it is
     * the first we have seen of it; never changes an existing status — a send
     * to an already-suppressed address does not revive it.
     *
     * A no-op unless address tracking is enabled.
     *
     * @param string|null $address
     *
     * @return void
     */
    protected function touchAddress( $address )
    {
        if (! config('postmaster.persistence.track_addresses', false) || empty($address)) {
            return;
        }

        $model = $this->addressModel();

        $record = $model->newQuery()->firstOrNew([
            'address' => $model::normalize($address),
        ]);

        $record->last_event_at = now();
        $record->save();
    }

    /**
     * Apply a webhook event to its recipient address: record the activity and
     * suppress the address when the event is a hard bounce, a complaint, or a
     * drop. Suppression is one-way here — events never un-suppress.
     *
     * A no-op unless address tracking is enabled.
     *
     * @param EmailEvent $event
     *
     * @return void
     */
    protected function applyEventToAddress( EmailEvent $event )
    {
        if (! config('postmaster.persistence.track_addresses', false)) {
            return;
        }

        $address = $event->getRecipient();

        if (empty($address)) {
            return;
        }

        $model = $this->addressModel();

        $record = $model->newQuery()->firstOrNew([
            'address' => $model::normalize($address),
        ]);

        $occurredAt = $event->getDate() ?? now();
        $record->last_event_at = $occurredAt;

        $reason = $this->suppressionReason($event);

        if ($reason !== null && ! $record->isSuppressed()) {
            $record->status = EmailAddress::STATUS_SUPPRESSED;
            $record->reason = $reason;
            $record->suppressed_at = $occurredAt;
        }

        $record->save();
    }

    /**
     * The reason an event suppresses its recipient, or null if it does not.
     * A complaint or a drop always suppresses; a bounce suppresses only when
     * permanent (a hard bounce or a reputation block), never a soft bounce.
     *
     * @param EmailEvent $event
     *
     * @return string|null
     */
    protected function suppressionReason( EmailEvent $event )
    {
        return match (true) {
            $event->getAction() === EmailEvent::EVENT_COMPLAINED => EmailEvent::EVENT_COMPLAINED,
            $event->getAction() === EmailEvent::EVENT_DROPPED    => EmailEvent::EVENT_DROPPED,
            $event->getAction() === EmailEvent::EVENT_BOUNCED && $event->isPermanent() => EmailEvent::EVENT_BOUNCED,
            default => null,
        };
    }
}
