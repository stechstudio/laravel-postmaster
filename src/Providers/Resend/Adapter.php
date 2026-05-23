<?php

namespace STS\Postmaster\Providers\Resend;

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
    protected $provider = "Resend";

    /**
     * @var array
     */
    protected $eventMap = [
        'email.sent'             => EmailEvent::STATUS_ACCEPTED,
        'email.delivered'        => EmailEvent::STATUS_DELIVERED,
        'email.delivery_delayed' => EmailEvent::STATUS_DEFERRED,
        'email.bounced'          => EmailEvent::STATUS_BOUNCED,
        'email.failed'           => EmailEvent::STATUS_BOUNCED,
        'email.complained'       => EmailEvent::STATUS_COMPLAINED,
        'email.opened'           => EmailEvent::STATUS_OPENED,
        'email.clicked'          => EmailEvent::STATUS_CLICKED,
    ];

    /**
     * @return string|null
     */
    public function status()
    {
        return Arr::get($this->eventMap, Arr::get($this->payload, 'type'));
    }

    /**
     * @return string|null
     */
    public function toAddress()
    {
        $to = Arr::get($this->payload, 'data.to');

        return is_array($to) ? Arr::first($to) : $to;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function occurredAt()
    {
        $createdAt = Arr::get($this->payload, 'created_at');

        return static::dateFromUnix($createdAt ? strtotime($createdAt) : null);
    }

    /**
     * @return string|null
     */
    public function providerMessageId()
    {
        return Arr::get($this->payload, 'data.email_id');
    }

    /**
     * @return Collection
     */
    public function tags()
    {
        return collect((array) Arr::get($this->payload, 'data.tags'));
    }

    /**
     * @return Collection
     */
    public function data()
    {
        return collect((array) Arr::get($this->payload, 'data.headers'));
    }

    /**
     * @return mixed
     */
    public function response()
    {
        return Arr::get($this->payload, 'data.bounce.message');
    }

    /**
     * @return mixed
     */
    public function code()
    {
        return null;
    }

    /**
     * @return mixed
     */
    public function reason()
    {
        return Arr::get($this->payload, 'data.bounce.subType')
            ?? Arr::get($this->payload, 'data.bounce.type');
    }

    /**
     * @return string|null
     */
    public function bounceType()
    {
        if ($this->status() !== EmailEvent::STATUS_BOUNCED) {
            return null;
        }

        return Arr::get($this->payload, 'data.bounce.type') === 'Permanent'
            ? EmailEvent::BOUNCE_HARD
            : EmailEvent::BOUNCE_SOFT;
    }

    /**
     * @return string|null
     */
    public function clickedUrl()
    {
        return Arr::get($this->payload, 'data.click.link');
    }

    /**
     * @param array $payload
     *
     * @return bool
     */
    public static function supports( array $payload )
    {
        return array_key_exists('type', $payload)
            && array_key_exists('data', $payload)
            && str_starts_with((string) Arr::get($payload, 'type'), 'email.');
    }
}
