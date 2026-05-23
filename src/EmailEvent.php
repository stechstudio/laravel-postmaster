<?php

namespace STS\Postmaster;

use DateTimeInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;
use STS\Postmaster\Concerns\HasStatusPredicates;
use STS\Postmaster\Contracts\Adapter;

/**
 * A normalized inbound email event. Every webhook becomes one of these
 * regardless of provider; consumers see the same shape whatever the source.
 */
class EmailEvent
{
    use Dispatchable;
    use HasStatusPredicates;

    const string STATUS_ACCEPTED   = "accepted";
    const string STATUS_SENT       = "sent";
    // Terminal status for a message intercepted by sandbox delivery mode: it
    // was recorded but never handed to a provider, so no webhooks will follow.
    const string STATUS_SANDBOXED  = "sandboxed";
    // Terminal status for a send we refused on the way out — the recipient
    // is on our suppression list — when block_suppressed is enabled.
    const string STATUS_BLOCKED    = "blocked";
    const string STATUS_DEFERRED   = "deferred";
    const string STATUS_DELIVERED  = "delivered";
    const string STATUS_BOUNCED    = "bounced";
    const string STATUS_DROPPED    = "dropped";
    const string STATUS_COMPLAINED = "complained";
    const string STATUS_OPENED     = "opened";
    const string STATUS_CLICKED    = "clicked";

    const string BOUNCE_HARD  = "hard";  // permanent — safe to suppress
    const string BOUNCE_SOFT  = "soft";  // transient — retry later
    const string BOUNCE_BLOCK = "block"; // blocked by reputation/policy

    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * The persisted email record this event was correlated to, by provider
     * message id. Set by UpdateMessageFromEvent; read by app listeners via
     * the emailMessage() accessor below.
     *
     * @var \STS\Postmaster\Models\EmailMessage|null
     */
    protected $emailMessage;

    /**
     * @param Adapter $adapter
     */
    public function __construct( Adapter $adapter )
    {
        $this->adapter = $adapter;
    }

    /**
     * @param Adapter $adapter
     *
     * @return EmailEvent|null
     */
    public static function create( Adapter $adapter )
    {
        return $adapter->isValid()
            ? new self($adapter)
            : null;
    }

    /**
     * @return Adapter
     */
    public function adapter()
    {
        return $this->adapter;
    }

    /**
     * The persisted email record this event was correlated to, by provider
     * message id. Gives any listener of your own a path back to the
     * originating message — and, through its related() / recipient()
     * relations, to the app models it was sent for:
     *
     *     $event->emailMessage()?->related
     *     $event->emailMessage()?->recipient
     *
     * Null when persistence is disabled, when the event carries no message
     * id, or for a listener that runs before the package's
     * UpdateMessageFromEvent (which is registered first, so an app listener
     * runs after it by default).
     *
     * @return \STS\Postmaster\Models\EmailMessage|null
     */
    public function emailMessage()
    {
        return $this->emailMessage;
    }

    /**
     * Associate the event with its persisted record. Called by
     * UpdateMessageFromEvent after the correlation lookup.
     *
     * @param \STS\Postmaster\Models\EmailMessage $message
     *
     * @return void
     */
    public function setEmailMessage( $message )
    {
        $this->emailMessage = $message;
    }

    /**
     * @return string
     */
    public function provider()
    {
        return $this->adapter->provider();
    }

    /**
     * The normalized lifecycle status — one of the STATUS_* constants.
     *
     * @return string|null
     */
    public function status()
    {
        return $this->adapter->status();
    }

    /**
     * Used by HasStatusPredicates to drive the is*() methods.
     *
     * @return string|null
     */
    protected function currentStatus()
    {
        return $this->status();
    }

    /**
     * @return string|null
     */
    public function providerMessageId()
    {
        return $this->adapter->providerMessageId();
    }

    /**
     * The address this event is about.
     *
     * @return string|null
     */
    public function toAddress()
    {
        return $this->adapter->toAddress();
    }

    /**
     * When the event happened, per the provider — or null if no usable
     * timestamp was supplied.
     *
     * @return \DateTimeImmutable|null
     */
    public function occurredAt()
    {
        return $this->adapter->occurredAt();
    }

    /**
     * @return mixed
     */
    public function response()
    {
        return $this->adapter->response();
    }

    /**
     * @return mixed
     */
    public function reason()
    {
        return $this->adapter->reason();
    }

    /**
     * @return mixed
     */
    public function code()
    {
        return $this->adapter->code();
    }

    /**
     * @return Collection
     */
    public function tags()
    {
        return $this->adapter->tags();
    }

    /**
     * @return Collection
     */
    public function data()
    {
        return $this->adapter->data();
    }

    /**
     * @return string|null
     */
    public function bounceType()
    {
        return $this->adapter->bounceType();
    }

    /**
     * @return bool
     */
    public function isPermanent()
    {
        return $this->adapter->isPermanent();
    }

    /**
     * The URL clicked on a click event (null for other events, or providers
     * that don't expose one).
     *
     * @return string|null
     */
    public function clickedUrl()
    {
        return $this->adapter->clickedUrl();
    }

    /**
     * @return array
     */
    public function payload()
    {
        return $this->adapter->payload();
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'provider'            => $this->adapter->provider(),
            'status'              => $this->adapter->status(),
            'provider_message_id' => $this->adapter->providerMessageId(),
            'to_address'          => $this->adapter->toAddress(),
            'occurred_at'         => $this->adapter->occurredAt()?->format(DateTimeInterface::ATOM),
            'bounce_type'         => $this->adapter->bounceType(),
            'reason'              => $this->adapter->reason(),
            'response'            => $this->adapter->response(),
            'code'                => $this->adapter->code(),
            'clicked_url'         => $this->adapter->clickedUrl(),
            'tags'                => $this->adapter->tags()->toArray(),
            'data'                => $this->adapter->data()->toArray(),
        ];
    }
}
