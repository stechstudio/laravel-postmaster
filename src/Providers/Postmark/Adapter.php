<?php

namespace STS\EmailEvents\Providers\Postmark;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use STS\EmailEvents\EmailEvent;
use STS\EmailEvents\Providers\AbstractAdapter;

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
        'Transient'     => EmailEvent::EVENT_DEFERRED,
        'Delivery'      => EmailEvent::EVENT_DELIVERED,
        'Bounce'        => EmailEvent::EVENT_BOUNCED,
        'SpamComplaint' => EmailEvent::EVENT_COMPLAINED,
        'Open'          => EmailEvent::EVENT_OPENED,
        'Click'         => EmailEvent::EVENT_CLICKED
    ];

    /**
     * @return mixed
     */
    public function getAction()
    {
        if (Arr::get($this->payload, 'RecordType') == "Bounce" && array_key_exists(Arr::get($this->payload,'Type'), $this->eventMap)) {
            return $this->eventMap[ Arr::get($this->payload, 'Type') ];
        }

        return Arr::get($this->eventMap, Arr::get($this->payload, 'RecordType'));
    }

    /**
     * @return mixed
     */
    public function getRecipient()
    {
        return Arr::get($this->payload, 'Recipient')
            ?? Arr::get($this->payload, 'Email');
    }

    /**
     * @return mixed
     */
    public function getTimestamp()
    {
        foreach(["DeliveredAt","ReceivedAt","BouncedAt"] as $dateField) {
            if(Arr::has($this->payload, $dateField)) {
                return strtotime($this->payload[$dateField]);
            }
        }
    }

    /**
     * @return mixed
     */
    public function getMessageId()
    {
        return Arr::get($this->payload, "MessageID");
    }

    /**
     * @return Collection
     */
    public function getTags()
    {
        return collect((array)Arr::get($this->payload, 'Tag'));
    }

    /**
     * @return Collection
     */
    public function getData()
    {
        return collect((array)Arr::get($this->payload, 'Metadata'));
    }

    /**
     * @return mixed
     */
    public function getResponse()
    {
        return Arr::get($this->payload, 'Details');
    }

    /**
     * @return mixed
     */
    public function getCode()
    {
        if ($this->getAction() == EmailEvent::EVENT_BOUNCED) {
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
    public function getReason()
    {
        return Arr::get($this->payload, 'Type');
    }

    /**
     * @return string|null
     */
    public function getBounceType()
    {
        if ($this->getAction() !== EmailEvent::EVENT_BOUNCED) {
            return null;
        }

        return Arr::get(
            $this->bounceTypeMap,
            Arr::get($this->payload, 'Type'),
            EmailEvent::BOUNCE_SOFT
        );
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