<?php

namespace STS\Postmaster\Concerns;

use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailMessage;

/**
 * Boolean predicates for the lifecycle statuses, shared by EmailEvent (where
 * "the status" is the event itself) and EmailMessage (where it's the latest
 * status recorded for the message). They are one-line conveniences over
 * `$x->status() === EmailEvent::STATUS_*`, surfaced as real methods so they
 * show up in IDE autocomplete — `__call()` magic is deliberately avoided.
 *
 * A consuming class implements currentStatus() to return whatever its
 * lifecycle status currently is (or null).
 */
trait HasStatusPredicates
{
    abstract protected function currentStatus(): ?string;

    public function isAccepted(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_ACCEPTED;
    }

    public function isSent(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_SENT;
    }

    /**
     * The terminal status for a message intercepted by sandbox delivery —
     * recorded but never handed to a provider, so no webhooks will follow.
     */
    public function isSandboxed(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_SANDBOXED;
    }

    /**
     * The terminal status for a send the package refused because the
     * recipient is on our suppression list (block_suppressed mode).
     */
    public function isBlocked(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_BLOCKED;
    }

    public function isDeferred(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_DEFERRED;
    }

    public function isDelivered(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_DELIVERED;
    }

    public function isBounced(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_BOUNCED;
    }

    public function isDropped(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_DROPPED;
    }

    public function isComplained(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_COMPLAINED;
    }

    public function isOpened(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_OPENED;
    }

    public function isClicked(): bool
    {
        return $this->currentStatus() === EmailEvent::STATUS_CLICKED;
    }

    /**
     * Whether the current status represents a delivery failure — bounced,
     * dropped, or complained. The aggregate concept the FAILED_STATUSES set
     * names.
     */
    public function isFailed(): bool
    {
        return in_array($this->currentStatus(), EmailMessage::FAILED_STATUSES, true);
    }
}
