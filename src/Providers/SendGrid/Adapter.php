<?php

namespace STS\Postmaster\Providers\SendGrid;

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
    protected $provider = "SendGrid";

    /**
     * @var string
     */
    protected static $userAgent = "SendGrid Event API";

    /**
     * @var array
     */
    protected $eventMap = [
        'processed'  => EmailEvent::STATUS_ACCEPTED,
        'deferred'   => EmailEvent::STATUS_DEFERRED,
        'delivered'  => EmailEvent::STATUS_DELIVERED,
        'bounce'     => EmailEvent::STATUS_BOUNCED,
        'dropped'    => EmailEvent::STATUS_DROPPED,
        'spamreport' => EmailEvent::STATUS_COMPLAINED,
        'open'       => EmailEvent::STATUS_OPENED,
        'click'      => EmailEvent::STATUS_CLICKED
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
        return Arr::get($this->payload, 'email');
    }

    /**
     * @return DateTimeImmutable|null
     */
    #[\Override]
    public function occurredAt()
    {
        return static::dateFromUnix(Arr::get($this->payload, 'timestamp'));
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function providerMessageId()
    {
        return Arr::get($this->payload, "smtp-id");
    }

    /**
     * @return Collection
     */
    #[\Override]
    public function tags()
    {
        return collect((array)Arr::get($this->payload, 'category'));
    }

    /**
     * @return Collection
     */
    #[\Override]
    public function data()
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
    #[\Override]
    public function response()
    {
        return Arr::get($this->payload, 'response', Arr::get($this->payload, 'useragent'));
    }

    /**
     * @return mixed
     */
    #[\Override]
    public function code()
    {
        return Arr::get($this->payload, 'status');
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
     * SendGrid splits failures across separate events: "dropped" (SendGrid
     * refused to send — always permanent) and "bounce" (carries a "type" of
     * either "bounce" or "blocked").
     *
     * @return string|null
     */
    #[\Override]
    public function bounceType()
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
        return array_key_exists('sg_message_id', $payload);
    }
}
