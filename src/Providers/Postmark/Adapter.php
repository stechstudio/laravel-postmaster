<?php

namespace STS\Postmaster\Providers\Postmark;

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
    protected $provider = "Postmark";

    /**
     * @var string
     */
    protected static $userAgent = "Postmark";

    /**
     * @var array
     */
    protected $eventMap = [
        'Transient'     => EmailEvent::STATUS_DEFERRED,
        'Delivery'      => EmailEvent::STATUS_DELIVERED,
        'Bounce'        => EmailEvent::STATUS_BOUNCED,
        'SpamComplaint' => EmailEvent::STATUS_COMPLAINED,
        'Open'          => EmailEvent::STATUS_OPENED,
        'Click'         => EmailEvent::STATUS_CLICKED
    ];

    /**
     * @return string|null
     */
    public function status()
    {
        if (Arr::get($this->payload, 'RecordType') == "Bounce" && array_key_exists(Arr::get($this->payload,'Type'), $this->eventMap)) {
            return $this->eventMap[ Arr::get($this->payload, 'Type') ];
        }

        return Arr::get($this->eventMap, Arr::get($this->payload, 'RecordType'));
    }

    /**
     * @return string|null
     */
    public function toAddress()
    {
        return Arr::get($this->payload, 'Recipient')
            ?? Arr::get($this->payload, 'Email');
    }

    /**
     * Postmark uses different date fields per record type — find whichever
     * one is present and convert to UTC DateTimeImmutable.
     *
     * @return DateTimeImmutable|null
     */
    public function occurredAt()
    {
        foreach (["DeliveredAt", "ReceivedAt", "BouncedAt"] as $dateField) {
            if (Arr::has($this->payload, $dateField)) {
                $parsed = strtotime($this->payload[$dateField]);

                return static::dateFromUnix($parsed === false ? null : $parsed);
            }
        }

        return null;
    }

    /**
     * @return string|null
     */
    public function providerMessageId()
    {
        return Arr::get($this->payload, "MessageID");
    }

    /**
     * @return Collection
     */
    public function tags()
    {
        return collect((array)Arr::get($this->payload, 'Tag'));
    }

    /**
     * @return Collection
     */
    public function data()
    {
        return collect((array)Arr::get($this->payload, 'Metadata'));
    }

    /**
     * @return mixed
     */
    public function response()
    {
        return Arr::get($this->payload, 'Details');
    }

    /**
     * @return mixed
     */
    public function code()
    {
        if ($this->status() == EmailEvent::STATUS_BOUNCED) {
            return Arr::get($this->payload, 'TypeCode');
        }
    }

    /**
     * Postmark bounce taxonomy (the "Type" field on a Bounce webhook).
     *
     * @var array
     */
    protected $bounceTypeMap = [
        'HardBounce'          => EmailEvent::BOUNCE_HARD,
        'BadEmailAddress'     => EmailEvent::BOUNCE_HARD,
        'ManuallyDeactivated' => EmailEvent::BOUNCE_HARD,
        'SoftBounce'          => EmailEvent::BOUNCE_SOFT,
        'DnsError'            => EmailEvent::BOUNCE_SOFT,
        'SMTPApiError'        => EmailEvent::BOUNCE_SOFT,
        'Blocked'             => EmailEvent::BOUNCE_BLOCK,
        'SpamNotification'    => EmailEvent::BOUNCE_BLOCK,
        'VirusNotification'   => EmailEvent::BOUNCE_BLOCK,
    ];

    /**
     * @return mixed
     */
    public function reason()
    {
        return Arr::get($this->payload, 'Type');
    }

    /**
     * @return string|null
     */
    public function bounceType()
    {
        if ($this->status() !== EmailEvent::STATUS_BOUNCED) {
            return null;
        }

        return Arr::get(
            $this->bounceTypeMap,
            Arr::get($this->payload, 'Type'),
            EmailEvent::BOUNCE_SOFT
        );
    }

    /**
     * @return string|null
     */
    public function clickedUrl()
    {
        return Arr::get($this->payload, 'OriginalLink');
    }

    /**
     * @param array $payload
     *
     * @return bool
     */
    public static function supports( array $payload )
    {
        return array_key_exists('MessageID', $payload) && array_key_exists('RecordType', $payload);
    }
}
