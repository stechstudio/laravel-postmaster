<?php

namespace STS\Postmaster\Providers\SendGrid;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\AbstractAdapter;

class Adapter extends AbstractAdapter
{
    /**
     * @var string
     */
    protected $provider = "SendGrid";

    /**
     * @var string
     */
    protected static $userAgent = "SendGrid Event API";

    /**
     * @var array
     */
    protected $eventMap = [
        'processed'  => EmailEvent::EMAIL_ACCEPTED,
        'deferred'   => EmailEvent::EVENT_DEFERRED,
        'delivered'  => EmailEvent::EVENT_DELIVERED,
        'bounce'     => EmailEvent::EVENT_BOUNCED,
        'dropped'    => EmailEvent::EVENT_DROPPED,
        'spamreport' => EmailEvent::EVENT_COMPLAINED,
        'open'       => EmailEvent::EVENT_OPENED,
        'click'      => EmailEvent::EVENT_CLICKED
    ];

    /**
     * We need to track which fields we _expect_ from the API, in order to determine
     * which fields are additional custom data. SendGrid merges custom data into
     * the main list, this is the only way we're going to pull those out if needed.
     *
     * @var array
     */
    protected $expectedFields = [
        "status", "sg_event_id", "sg_message_id", "event", "email", "timestamp", "smtp-id", "category", "newsletter",
        "asm_group_id", "reason", "type", "ip", "tls", "cert_err", "pool", "useragent", "url", "url_offset", "attempt", "response",
        "marketing_campaign_id", "marketing_campaign_name", "post_type", "marketing_campaign_version", "marketing_campaign_split_id"
    ];

    /**
     * @return mixed
     */
    public function getAction()
    {
        return Arr::get($this->eventMap, Arr::get($this->payload, 'event'));
    }

    /**
     * @return mixed
     */
    public function getRecipient()
    {
        return Arr::get($this->payload, 'email');

    }

    /**
     * @return mixed
     */
    public function getTimestamp()
    {
        return Arr::get($this->payload, "timestamp");
    }

    /**
     * @return mixed
     */
    public function getMessageId()
    {
        return Arr::get($this->payload, "smtp-id");
    }

    /**
     * @return Collection
     */
    public function getTags()
    {
        return collect((array)Arr::get($this->payload, 'category'));
    }

    /**
     * @return Collection
     */
    public function getData()
    {
        return collect(
            array_diff_key(
                $this->payload, array_flip($this->expectedFields)
            )
        );
    }

    /**
     * @return mixed
     */
    public function getResponse()
    {
        return Arr::get($this->payload, 'response', Arr::get($this->payload, 'useragent'));
    }

    /**
     * @return mixed
     */
    public function getCode()
    {
        return Arr::get($this->payload, 'status');
    }

    /**
     * @return mixed
     */
    public function getReason()
    {
        return Arr::get($this->payload, 'reason');
    }

    /**
     * SendGrid splits failures across separate events: "dropped" (SendGrid
     * refused to send — always permanent) and "bounce" (carries a "type" of
     * either "bounce" or "blocked").
     *
     * @return string|null
     */
    public function getBounceType()
    {
        $event = Arr::get($this->payload, 'event');

        if ($event === 'dropped') {
            return EmailEvent::BOUNCE_HARD;
        }

        if ($event !== 'bounce') {
            return null;
        }

        return Arr::get($this->payload, 'type') === 'blocked'
            ? EmailEvent::BOUNCE_BLOCK
            : EmailEvent::BOUNCE_HARD;
    }

    /**
     * @return string|null
     */
    public function getUrl()
    {
        return Arr::get($this->payload, 'url');
    }

    /**
     * @param array $payload
     *
     * @return bool
     */
    public static function supports( array $payload )
    {
        return array_key_exists('sg_message_id', $payload);
    }
}