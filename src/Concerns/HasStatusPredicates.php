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
    /** @return string|null */
    abstract protected function currentStatus();

    /** @return bool */
    public function isAccepted()
    {
        return $this->currentStatus() === EmailEvent::STATUS_ACCEPTED;
    }

    /** @return bool */
    public function isSent()
    {
        return $this->currentStatus() === EmailEvent::STATUS_SENT;
    }

    /**
     * The terminal status for a message intercepted by sandbox delivery —
     * recorded but never handed to a provider, so no webhooks will follow.
     *
     * @return bool
     */
    public function isSandboxed()
    {
        return $this->currentStatus() === EmailEvent::STATUS_SANDBOXED;
    }

    /**
     * The terminal status for a send the package refused because the
     * recipient is on our suppression list (block_suppressed mode).
     *
     * @return bool
     */
    public function isBlocked()
    {
        return $this->currentStatus() === EmailEvent::STATUS_BLOCKED;
    }

    /** @return bool */
    public function isDeferred()
    {
        return $this->currentStatus() === EmailEvent::STATUS_DEFERRED;
    }

    /** @return bool */
    public function isDelivered()
    {
        return $this->currentStatus() === EmailEvent::STATUS_DELIVERED;
    }

    /** @return bool */
    public function isBounced()
    {
        return $this->currentStatus() === EmailEvent::STATUS_BOUNCED;
    }

    /** @return bool */
    public function isDropped()
    {
        return $this->currentStatus() === EmailEvent::STATUS_DROPPED;
    }

    /** @return bool */
    public function isComplained()
    {
        return $this->currentStatus() === EmailEvent::STATUS_COMPLAINED;
    }

    /** @return bool */
    public function isOpened()
    {
        return $this->currentStatus() === EmailEvent::STATUS_OPENED;
    }

    /** @return bool */
    public function isClicked()
    {
        return $this->currentStatus() === EmailEvent::STATUS_CLICKED;
    }

    /**
     * Whether the current status represents a delivery failure — bounced,
     * dropped, or complained. The aggregate concept the FAILED_STATUSES set
     * names.
     *
     * @return bool
     */
    public function isFailed()
    {
        return in_array($this->currentStatus(), EmailMessage::FAILED_STATUSES, true);
    }
}
