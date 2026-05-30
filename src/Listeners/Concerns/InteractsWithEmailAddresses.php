<?php

namespace STS\Postmaster\Listeners\Concerns;

use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;

trait InteractsWithEmailAddresses
{
    /**
     * Record that we sent to an address. Creates the row (active) if it is
     * the first we have seen of it; never changes an existing status — a send
     * to an already-suppressed address does not revive it.
     *
     * A no-op unless address tracking is enabled.
     */
    protected function touchAddress(?string $address): void
    {
        if (! config('postmaster.persistence.track_addresses', false) || empty($address)) {
            return;
        }

        $record = EmailAddress::model()->newQuery()->firstOrNew([
            'address' => EmailAddress::normalize($address),
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
     */
    protected function applyEventToAddress(EmailEvent $event): void
    {
        if (! config('postmaster.persistence.track_addresses', false)) {
            return;
        }

        $address = $event->toAddress();

        if (empty($address)) {
            return;
        }

        $record = EmailAddress::model()->newQuery()->firstOrNew([
            'address' => EmailAddress::normalize($address),
        ]);

        $occurredAt = $event->occurredAt() ?? now();
        $record->last_event_at = $occurredAt;
        $record->recordProvider($event->provider());

        $reason = $this->suppressionReason($event);

        if ($reason !== null && ! $record->isSuppressed()) {
            $record->status = EmailAddress::STATUS_SUPPRESSED;
            $record->reason = $reason;
            $record->suppressed_at = $occurredAt;
        }

        // The converse: a delivery proves the address works *now*, so an
        // automatic suppression (bounced/dropped/complained) gets lifted.
        // Manual suppressions are operator-asserted decisions and are
        // never auto-cleared — same rule postmaster:sync follows.
        $cleared = $this->maybeAutoClear($record, $event, $occurredAt);

        $record->save();

        if ($cleared) {
            $record->logActivity([
                'status'      => EmailActivity::STATUS_UNSUPPRESSED,
                'provider'    => $event->provider(),
                'reason'      => null,
                'response'    => 'Auto-cleared after a delivery proved the address works.',
                'source'      => 'webhook',
                'occurred_at' => $occurredAt,
            ]);
        }
    }

    /**
     * If a delivered event lands on an automatically-suppressed address,
     * flip it back to active. Returns true when the row transitioned —
     * the caller logs the activity entry after save().
     */
    protected function maybeAutoClear(EmailAddress $record, EmailEvent $event, $occurredAt): bool
    {
        if (! $event->isDelivered() || ! $record->isSuppressed()) {
            return false;
        }

        if ($record->reason === EmailAddress::REASON_MANUAL) {
            return false;
        }

        $record->status = EmailAddress::STATUS_ACTIVE;
        $record->reason = null;
        $record->suppressed_at = null;

        return true;
    }

    /**
     * The reason an event suppresses its recipient, or null if it does not.
     * A complaint or a drop always suppresses; a bounce suppresses only when
     * permanent (a hard bounce or a reputation block), never a soft bounce.
     */
    protected function suppressionReason(EmailEvent $event): ?string
    {
        return match (true) {
            $event->status() === EmailEvent::STATUS_COMPLAINED => EmailEvent::STATUS_COMPLAINED,
            $event->status() === EmailEvent::STATUS_DROPPED    => EmailEvent::STATUS_DROPPED,
            $event->status() === EmailEvent::STATUS_BOUNCED && $event->isPermanent() => EmailEvent::STATUS_BOUNCED,
            default => null,
        };
    }
}
