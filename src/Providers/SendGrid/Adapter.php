<?php

namespace STS\Postmaster\Providers\SendGrid;

use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\AbstractAdapter;

class Adapter extends AbstractAdapter
{
    protected string $provider = "SendGrid";

    protected static ?string $userAgent = "SendGrid Event API";

    protected array $eventMap = [
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

    #[\Override]
    public function status(): ?string
    {
        return Arr::get($this->eventMap, Arr::get($this->payload, 'event'));
    }

    #[\Override]
    public function toAddress(): ?string
    {
        return Arr::get($this->payload, 'email');
    }

    #[\Override]
    public function occurredAt(): ?\DateTimeImmutable
    {
        return static::dateFromUnix(Arr::get($this->payload, 'timestamp'));
    }

    #[\Override]
    public function providerMessageId(): ?string
    {
        // SendGrid sends two ids in every webhook payload. `sg_message_id`
        // is SendGrid's canonical id of the form `<queue-id>.<filter-tags>`;
        // its prefix is the same value SendGrid returns in the SMTP 250 OK
        // response, which is what Symfony's SMTP transport records on the
        // SentMessage. Splitting at the first dot gives us a value that
        // matches what RecordOutboundMessage stored at send time.
        //
        // `smtp-id` (the other id) is the email's Message-ID header value,
        // but Symfony only stamps that on the outgoing wire, never on the
        // Email object itself — so the SMTP-side flow can't record it for
        // correlation. sg_message_id is the more reliable match.
        $sgMessageId = Arr::get($this->payload, 'sg_message_id');

        if (is_string($sgMessageId) && $sgMessageId !== '') {
            return strstr($sgMessageId, '.', true) ?: $sgMessageId;
        }

        return null;
    }

    #[\Override]
    public function tags(): \Illuminate\Support\Collection
    {
        return collect((array)Arr::get($this->payload, 'category'));
    }

    #[\Override]
    public function data(): \Illuminate\Support\Collection
    {
        return collect(
            array_diff_key(
                $this->payload, array_flip($this->expectedFields)
            )
        );
    }

    #[\Override]
    public function response(): mixed
    {
        return Arr::get($this->payload, 'response', Arr::get($this->payload, 'useragent'));
    }

    #[\Override]
    public function code(): mixed
    {
        return Arr::get($this->payload, 'status');
    }

    #[\Override]
    public function reason(): mixed
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
    public function bounceType(): ?string
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

    #[\Override]
    public function clickedUrl(): ?string
    {
        return Arr::get($this->payload, 'url');
    }

    #[\Override]
    public static function supports(array $payload): bool
    {
        return array_key_exists('sg_message_id', $payload);
    }
}
