<?php

namespace STS\Postmaster;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;
use STS\Postmaster\Contracts\Adapter;

/**
 * Class EmailEvent
 * @package STS\EmailEventParser
 */
class EmailEvent
{
    use Dispatchable;

    const EMAIL_ACCEPTED = "accepted";
    const EVENT_SENT = "sent";
    // Terminal status for a message intercepted by sandbox delivery mode: it
    // was recorded but never handed to a provider, so no webhooks will follow.
    const EVENT_SANDBOX = "sandbox";
    const EVENT_DEFERRED = "deferred";
    const EVENT_DELIVERED = "delivered";
    const EVENT_BOUNCED = "bounced";
    const EVENT_DROPPED = "dropped";
    const EVENT_COMPLAINED = "complained";
    const EVENT_OPENED = "opened";
    const EVENT_CLICKED = "clicked";

    const BOUNCE_HARD = "hard";   // permanent — safe to suppress
    const BOUNCE_SOFT = "soft";   // transient — retry later
    const BOUNCE_BLOCK = "block"; // blocked by reputation/policy

    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * The persisted email record this event was correlated to, by provider
     * message id. Set by the package's UpdateMessageFromEvent listener, so it
     * gives any listener of your own a path back to the originating message —
     * and, through its related() relation, to the model it was sent for:
     *
     *     $event->emailMessage?->related
     *
     * Null when persistence is disabled, when the event carries no message id,
     * or for a listener that runs before UpdateMessageFromEvent (the package's
     * listener is registered first, so a normal app listener runs after it).
     *
     * @var \STS\Postmaster\Models\EmailMessage|null
     */
    public $emailMessage;

    /**
     * EmailEvent constructor.
     *
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
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * @return string
     */
    public function getProvider()
    {
        return $this->adapter->getProvider();
    }

    /**
     * @return string|null
     */
    public function getAction()
    {
        return $this->adapter->getAction();
    }

    /**
     * @return string|null
     */
    public function getMessageId()
    {
        return $this->adapter->getMessageId();
    }

    /**
     * @return string|null
     */
    public function getRecipient()
    {
        return $this->adapter->getRecipient();
    }

    /**
     * @return int|null
     */
    public function getTimestamp()
    {
        return $this->adapter->getTimestamp();
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getDate()
    {
        return $this->adapter->getDate();
    }

    /**
     * @return mixed
     */
    public function getResponse()
    {
        return $this->adapter->getResponse();
    }

    /**
     * @return mixed
     */
    public function getReason()
    {
        return $this->adapter->getReason();
    }

    /**
     * @return mixed
     */
    public function getCode()
    {
        return $this->adapter->getCode();
    }

    /**
     * @return Collection
     */
    public function getTags()
    {
        return $this->adapter->getTags();
    }

    /**
     * @return Collection
     */
    public function getData()
    {
        return $this->adapter->getData();
    }

    /**
     * @return string|null
     */
    public function getBounceType()
    {
        return $this->adapter->getBounceType();
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
    public function getUrl()
    {
        return $this->adapter->getUrl();
    }

    /**
     * @return array
     */
    public function getPayload()
    {
        return $this->adapter->getPayload();
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'provider'  => $this->adapter->getProvider(),
            'event'     => $this->adapter->getAction(),
            'timestamp' => $this->adapter->getTimestamp(),
            'date'      => $this->adapter->getDate()?->format(\DateTimeInterface::ATOM),
            'recipient' => $this->adapter->getRecipient(),
            'messageId' => $this->adapter->getMessageId(),
            'tags'      => $this->adapter->getTags()->toArray(),
            'data'      => $this->adapter->getData()->toArray(),
            'response'  => $this->adapter->getResponse(),
            'reason'    => $this->adapter->getReason(),
            'code'      => $this->adapter->getCode(),
            'bounceType' => $this->adapter->getBounceType(),
        ];
    }
}