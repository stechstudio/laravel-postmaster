<?php

namespace STS\Postmaster\Providers;

use DateTimeImmutable;
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
        return is_string($this->status()) && is_string($this->toAddress());
    }

    /**
     * @return string|null
     */
    abstract public function status();

    /**
     * @return string|null
     */
    abstract public function providerMessageId();

    /**
     * @return string|null
     */
    abstract public function toAddress();

    /**
     * @return DateTimeImmutable|null
     */
    abstract public function occurredAt();

    /**
     * @return mixed
     */
    abstract public function response();

    /**
     * @return mixed
     */
    abstract public function reason();

    /**
     * @return mixed
     */
    abstract public function code();

    /**
     * Normalized bounce severity, or null when this is not a bounce.
     *
     * @return string|null one of EmailEvent::BOUNCE_HARD|BOUNCE_SOFT|BOUNCE_BLOCK
     */
    abstract public function bounceType();

    /**
     * The clicked URL for a click event. The default returns null — adapters
     * for providers that expose a click URL override this.
     *
     * @return string|null
     */
    public function clickedUrl()
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
        return in_array($this->bounceType(), [
            EmailEvent::BOUNCE_HARD,
            EmailEvent::BOUNCE_BLOCK,
        ], true);
    }

    /**
     * @return Collection
     */
    abstract public function tags();

    /**
     * @return Collection
     */
    abstract public function data();

    /**
     * @param array $payload
     *
     * @return bool
     */
    abstract public static function supports( array $payload );

    /**
     * @return string
     */
    public function provider()
    {
        return $this->provider;
    }

    /**
     * @return array
     */
    public function payload()
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

    /**
     * Convert a unix-seconds timestamp into a UTC DateTimeImmutable. A shared
     * helper for adapters whose payload exposes the time as an int (or a
     * string they've already parsed to one).
     *
     * @param int|null $unix
     *
     * @return DateTimeImmutable|null
     */
    protected static function dateFromUnix( $unix )
    {
        return is_int($unix) ? new DateTimeImmutable('@'.$unix) : null;
    }
}
