<?php

namespace STS\Postmaster\Providers\Mailgun;

use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\AbstractAdapter;

class Adapter extends AbstractAdapter
{
    /**
     * @var string
     */
    protected $provider = "Mailgun";

    /**
     * @var string
     */
    protected static $userAgent = "mailgun/*";

    protected $signature;

    /**
     * @var array
     */
    protected $eventMap = [
        'delivered'  => EmailEvent::STATUS_DELIVERED,
        'failed'     => EmailEvent::STATUS_BOUNCED,
        'complained' => EmailEvent::STATUS_COMPLAINED,
        'opened'     => EmailEvent::STATUS_OPENED,
        'clicked'    => EmailEvent::STATUS_CLICKED
    ];

    public function __construct( $payload )
    {
        parent::__construct($payload['event-data']);

        $this->signature = $payload['signature'];
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function status()
    {
        return Arr::get($this->eventMap, Arr::get($this->payload, 'event'));
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function toAddress()
    {
        return Arr::get($this->payload, 'recipient');
    }

    /**
     * Mailgun's timestamp is unix seconds with millisecond precision (a
     * float); take the integer portion.
     *
     * @return DateTimeImmutable|null
     */
    #[\Override]
    public function occurredAt()
    {
        $ts = Arr::get($this->payload, 'timestamp');

        return static::dateFromUnix(is_numeric($ts) ? (int) $ts : null);
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function providerMessageId()
    {
        return Arr::get($this->payload, "id");
    }

    /**
     * @return Collection
     */
    #[\Override]
    public function tags()
    {
        return collect((array)Arr::get($this->payload, 'tags'));
    }

    /**
     * @return Collection
     */
    #[\Override]
    public function data()
    {
        return collect((array)Arr::get($this->payload, 'user-variables'));
    }

    /**
     * @return mixed
     */
    #[\Override]
    public function response()
    {
        return Arr::has($this->payload, 'delivery-status.description')
            ? Arr::get($this->payload, 'delivery-status.description')
            : Arr::get($this->payload, 'delivery-status.message');
    }

    /**
     * @return mixed
     */
    #[\Override]
    public function code()
    {
        return Arr::get($this->payload, 'delivery-status.code');
    }

    /**
     * @return mixed
     */
    #[\Override]
    public function reason()
    {
        return Arr::get($this->payload, 'reason');
    }

    /**
     * Mailgun "failed" events carry a severity of "permanent" or "temporary".
     *
     * @return string|null
     */
    #[\Override]
    public function bounceType()
    {
        if ($this->status() !== EmailEvent::STATUS_BOUNCED) {
            return null;
        }

        return Arr::get($this->payload, 'severity') === 'permanent'
            ? EmailEvent::BOUNCE_HARD
            : EmailEvent::BOUNCE_SOFT;
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function clickedUrl()
    {
        return Arr::get($this->payload, 'url');
    }

    /**
     * @param array $payload
     *
     * @return bool
     */
    #[\Override]
    public static function supports( array $payload )
    {
        return array_key_exists('signature', $payload) && array_key_exists('event-data', $payload);
    }
}
