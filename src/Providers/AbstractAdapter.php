<?php

namespace STS\Postmaster\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use STS\Postmaster\Contracts\Adapter;
use STS\Postmaster\EmailEvent;

abstract class AbstractAdapter implements Adapter
{
    /**
     * @var string
     */
    protected static $userAgent;

    /**
     * @var array
     */
    protected $payload;

    /**
     * @var array
     */
    protected $eventMap = [];

    /**
     * @var string
     */
    protected $provider;

    /**
     * AbstractAdapter constructor.
     *
     * @param $payload
     */
    public function __construct( $payload )
    {
        $this->payload = $payload;
    }

    /**
     * If known, provide the user-agent pattern we expect to be used by the email provider
     */
    public static function getUserAgent()
    {
        return static::$userAgent;
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        return is_string($this->getAction()) && is_string($this->getRecipient());
    }

    /**
     * @return string
     */
    abstract public function getAction();

    /**
     * @return string
     */
    abstract public function getMessageId();

    /**
     * @return string
     */
    abstract public function getRecipient();

    /**
     * @return int
     */
    abstract public function getTimestamp();

    /**
     * @return mixed
     */
    abstract public function getResponse();

    /**
     * @return mixed
     */
    abstract public function getReason();

    /**
     * @return mixed
     */
    abstract public function getCode();

    /**
     * Normalized bounce severity, or null when this is not a bounce.
     *
     * @return string|null one of EmailEvent::BOUNCE_HARD|BOUNCE_SOFT|BOUNCE_BLOCK
     */
    abstract public function getBounceType();

    /**
     * The clicked URL for a click event. The default returns null — adapters
     * for providers that expose a click URL override this.
     *
     * @return string|null
     */
    public function getUrl()
    {
        return null;
    }

    /**
     * Whether this event represents a permanent failure — a hard bounce or a
     * block. These are safe to suppress; soft bounces are not.
     *
     * @return bool
     */
    public function isPermanent()
    {
        return in_array($this->getBounceType(), [
            EmailEvent::BOUNCE_HARD,
            EmailEvent::BOUNCE_BLOCK,
        ], true);
    }

    /**
     * The event timestamp as a DateTimeImmutable (UTC), or null when the
     * provider did not supply a usable timestamp. Convenience wrapper around
     * getTimestamp(), which returns the raw unix integer.
     *
     * @return \DateTimeImmutable|null
     */
    public function getDate()
    {
        $timestamp = $this->getTimestamp();

        return is_int($timestamp)
            ? new \DateTimeImmutable('@' . $timestamp)
            : null;
    }

    /**
     * @return Collection
     */
    abstract public function getTags();

    /**
     * @return Collection
     */
    abstract public function getData();

    /**
     * @param array $payload
     *
     * @return bool
     */
    abstract public static function supports( array $payload );

    /**
     * @return string
     */
    public function getProvider()
    {
        return $this->provider;
    }

    /**
     * @return array
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * @param $attribute
     *
     * @return mixed
     */
    public function get($attribute)
    {
        return Arr::get($this->payload, $attribute);
    }
}