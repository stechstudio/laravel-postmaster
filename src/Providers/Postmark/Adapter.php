<?php

namespace STS\Postmaster\Providers\Postmark;

use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\AbstractAdapter;

class Adapter extends AbstractAdapter
{
    protected string $provider = "Postmark";

    protected static ?string $userAgent = "Postmark";

    protected array $eventMap = [
        'Transient'     => EmailEvent::STATUS_DEFERRED,
        'Delivery'      => EmailEvent::STATUS_DELIVERED,
        'Bounce'        => EmailEvent::STATUS_BOUNCED,
        'SpamComplaint' => EmailEvent::STATUS_COMPLAINED,
        'Open'          => EmailEvent::STATUS_OPENED,
        'Click'         => EmailEvent::STATUS_CLICKED
    ];

    #[\Override]
    public function status(): ?string
    {
        if (Arr::get($this->payload, 'RecordType') == "Bounce" && array_key_exists(Arr::get($this->payload,'Type'), $this->eventMap)) {
            return $this->eventMap[ Arr::get($this->payload, 'Type') ];
        }

        return Arr::get($this->eventMap, Arr::get($this->payload, 'RecordType'));
    }

    #[\Override]
    public function toAddress(): ?string
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
    #[\Override]
    public function occurredAt(): ?\DateTimeImmutable
    {
        foreach (["DeliveredAt", "ReceivedAt", "BouncedAt"] as $dateField) {
            if (Arr::has($this->payload, $dateField)) {
                $parsed = strtotime($this->payload[$dateField]);

                return static::dateFromUnix($parsed === false ? null : $parsed);
            }
        }

        return null;
    }

    #[\Override]
    public function providerMessageId(): ?string
    {
        return Arr::get($this->payload, "MessageID");
    }

    #[\Override]
    public function tags(): \Illuminate\Support\Collection
    {
        return collect((array)Arr::get($this->payload, 'Tag'));
    }

    #[\Override]
    public function data(): \Illuminate\Support\Collection
    {
        return collect((array)Arr::get($this->payload, 'Metadata'));
    }

    #[\Override]
    public function response(): mixed
    {
        return Arr::get($this->payload, 'Details');
    }

    #[\Override]
    public function code(): mixed
    {
        if ($this->status() == EmailEvent::STATUS_BOUNCED) {
            return Arr::get($this->payload, 'TypeCode');
        }

        return null;
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

    #[\Override]
    public function reason(): mixed
    {
        return Arr::get($this->payload, 'Type');
    }

    #[\Override]
    public function bounceType(): ?string
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

    #[\Override]
    public function clickedUrl(): ?string
    {
        return Arr::get($this->payload, 'OriginalLink');
    }

    #[\Override]
    public static function supports(array $payload): bool
    {
        return array_key_exists('MessageID', $payload) && array_key_exists('RecordType', $payload);
    }
}
