<?php

namespace STS\Postmaster\Providers\Resend;

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
        'email.sent'             => EmailEvent::EMAIL_ACCEPTED,
        'email.delivered'        => EmailEvent::EVENT_DELIVERED,
        'email.delivery_delayed' => EmailEvent::EVENT_DEFERRED,
        'email.bounced'          => EmailEvent::EVENT_BOUNCED,
        'email.failed'           => EmailEvent::EVENT_BOUNCED,
        'email.complained'       => EmailEvent::EVENT_COMPLAINED,
        'email.opened'           => EmailEvent::EVENT_OPENED,
        'email.clicked'          => EmailEvent::EVENT_CLICKED,
    ];

    /**
     * @return mixed
     */
    public function getAction()
    {
        return Arr::get($this->eventMap, Arr::get($this->payload, 'type'));
    }

    /**
     * @return mixed
     */
    public function getRecipient()
    {
        $to = Arr::get($this->payload, 'data.to');

        return is_array($to) ? Arr::first($to) : $to;
    }

    /**
     * @return int|null
     */
    public function getTimestamp()
    {
        $createdAt = Arr::get($this->payload, 'created_at');

        return $createdAt ? strtotime($createdAt) : null;
    }

    /**
     * @return mixed
     */
    public function getMessageId()
    {
        return Arr::get($this->payload, 'data.email_id');
    }

    /**
     * @return Collection
     */
    public function getTags()
    {
        return collect((array) Arr::get($this->payload, 'data.tags'));
    }

    /**
     * @return Collection
     */
    public function getData()
    {
        return collect((array) Arr::get($this->payload, 'data.headers'));
    }

    /**
     * @return mixed
     */
    public function getResponse()
    {
        return Arr::get($this->payload, 'data.bounce.message');
    }

    /**
     * @return mixed
     */
    public function getCode()
    {
        return null;
    }

    /**
     * @return mixed
     */
    public function getReason()
    {
        return Arr::get($this->payload, 'data.bounce.subType')
            ?? Arr::get($this->payload, 'data.bounce.type');
    }

    /**
     * @return string|null
     */
    public function getBounceType()
    {
        if ($this->getAction() !== EmailEvent::EVENT_BOUNCED) {
            return null;
        }

        return Arr::get($this->payload, 'data.bounce.type') === 'Permanent'
            ? EmailEvent::BOUNCE_HARD
            : EmailEvent::BOUNCE_SOFT;
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
