<?php

namespace STS\Postmaster\Providers\Resend;

use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\AbstractAdapter;

class Adapter extends AbstractAdapter
{
    protected string $provider = "Resend";

    protected array $eventMap = [
        'email.sent'             => EmailEvent::STATUS_ACCEPTED,
        'email.delivered'        => EmailEvent::STATUS_DELIVERED,
        'email.delivery_delayed' => EmailEvent::STATUS_DEFERRED,
        'email.bounced'          => EmailEvent::STATUS_BOUNCED,
        'email.failed'           => EmailEvent::STATUS_BOUNCED,
        'email.complained'       => EmailEvent::STATUS_COMPLAINED,
        'email.opened'           => EmailEvent::STATUS_OPENED,
        'email.clicked'          => EmailEvent::STATUS_CLICKED,
    ];

    #[\Override]
    public function status(): ?string
    {
        return Arr::get($this->eventMap, Arr::get($this->payload, 'type'));
    }

    #[\Override]
    public function toAddress(): ?string
    {
        $to = Arr::get($this->payload, 'data.to');

        return is_array($to) ? Arr::first($to) : $to;
    }

    #[\Override]
    public function occurredAt(): ?\DateTimeImmutable
    {
        $createdAt = Arr::get($this->payload, 'created_at');

        return static::dateFromUnix($createdAt ? strtotime($createdAt) : null);
    }

    #[\Override]
    public function providerMessageId(): ?string
    {
        return Arr::get($this->payload, 'data.email_id');
    }

    #[\Override]
    public function tags(): \Illuminate\Support\Collection
    {
        return collect((array) Arr::get($this->payload, 'data.tags'));
    }

    #[\Override]
    public function data(): \Illuminate\Support\Collection
    {
        return collect((array) Arr::get($this->payload, 'data.headers'));
    }

    #[\Override]
    public function response(): mixed
    {
        return Arr::get($this->payload, 'data.bounce.message');
    }

    #[\Override]
    public function code(): mixed
    {
        return null;
    }

    #[\Override]
    public function reason(): mixed
    {
        return Arr::get($this->payload, 'data.bounce.subType')
            ?? Arr::get($this->payload, 'data.bounce.type');
    }

    #[\Override]
    public function bounceType(): ?string
    {
        if ($this->status() !== EmailEvent::STATUS_BOUNCED) {
            return null;
        }

        return Arr::get($this->payload, 'data.bounce.type') === 'Permanent'
            ? EmailEvent::BOUNCE_HARD
            : EmailEvent::BOUNCE_SOFT;
    }

    #[\Override]
    public function clickedUrl(): ?string
    {
        return Arr::get($this->payload, 'data.click.link');
    }

    #[\Override]
    public static function supports(array $payload): bool
    {
        return array_key_exists('type', $payload)
            && array_key_exists('data', $payload)
            && str_starts_with((string) Arr::get($payload, 'type'), 'email.');
    }
}
