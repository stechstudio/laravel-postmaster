<?php

namespace STS\Postmaster\Providers\Helo;

use DateMalformedStringException;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\AbstractAdapter;

class Adapter extends AbstractAdapter
{
    protected string $provider = 'Helo';

    protected array $eventMap = [
        'message-accepted' => EmailEvent::STATUS_ACCEPTED,
        'email-delivered' => EmailEvent::STATUS_DELIVERED,
        'email-bounced' => EmailEvent::STATUS_BOUNCED,
        'email-opened' => EmailEvent::STATUS_OPENED,
        'link-clicked' => EmailEvent::STATUS_CLICKED,
        'recipient-complained' => EmailEvent::STATUS_COMPLAINED,
        'recipient-unsubscribed' => EmailEvent::STATUS_UNSUBSCRIBED,
        'recipient-resubscribed' => EmailEvent::STATUS_RESUBSCRIBED,
    ];

    public function status(): ?string
    {
        return $this->eventMap[$this->string('eventType') ?? ''] ?? null;
    }

    public function toAddress(): ?string
    {
        return $this->string('recipient');
    }

    public function providerMessageId(): ?string
    {
        return $this->string('messageId');
    }

    public function occurredAt(): ?DateTimeImmutable
    {
        if (! $timestamp = $this->string('timestamp')) {
            return null;
        }

        try {
            return new DateTimeImmutable($timestamp);
        } catch (DateMalformedStringException) {
            return null;
        }
    }

    public function response(): mixed
    {
        // Bounces carry the receiving server's diagnostic in details.code.
        return $this->get('details.response') ?? $this->get('details.code');
    }

    public function reason(): mixed
    {
        return $this->get('details.subType') ?? $this->get('details.type');
    }

    /** The enhanced status (5.1.1) or reply code (550) from the bounce diagnostic. */
    public function code(): mixed
    {
        $diagnostic = $this->string('details.code') ?? '';

        return preg_match('/\b[245]\.\d{1,3}\.\d{1,3}\b/', $diagnostic, $match)
            || preg_match('/\b[245]\d\d\b/', $diagnostic, $match) ? $match[0] : null;
    }

    public function bounceType(): ?string
    {
        if ($this->status() !== EmailEvent::STATUS_BOUNCED) {
            return null;
        }

        // Live events send Hard/Soft; Helo's schema documents Permanent/Transient.
        return match ($this->get('details.type')) {
            'Hard', 'Permanent' => EmailEvent::BOUNCE_HARD,
            'Soft', 'Transient' => EmailEvent::BOUNCE_SOFT,
            default => null,
        };
    }

    public function clickedUrl(): ?string
    {
        return $this->status() === EmailEvent::STATUS_CLICKED ? $this->string('details.link') : null;
    }

    public function tags(): Collection
    {
        return collect((array) $this->get('tags'));
    }

    public function data(): Collection
    {
        return collect((array) $this->get('metadata'));
    }

    public function isValid(): bool
    {
        return parent::isValid() && $this->toAddress() !== '' && ! empty($this->providerMessageId());
    }

    public static function supports(array $payload): bool
    {
        return isset($payload['eventType'], $payload['messageId'], $payload['channelId']);
    }

    public static function expand(array $payload): array
    {
        // Postmaster's own outbound record already says "sent", so Helo's
        // hand-off event adds nothing. Drop it without an invalid-event log.
        if (($payload['eventType'] ?? null) === 'message-processed') {
            return [];
        }

        if (($payload['eventType'] ?? null) !== 'message-accepted') {
            return [$payload];
        }

        if (! is_array($payload['recipients'] ?? null) || $payload['recipients'] === []) {
            return [$payload];
        }

        return array_map(fn ($recipient) => array_replace($payload, ['recipient' => $recipient]), $payload['recipients']);
    }

    protected function string(string $key): ?string
    {
        $value = $this->get($key);

        return is_string($value) ? $value : null;
    }
}
