<?php

namespace STS\Postmaster\Providers\MailerSend;

use DateMalformedStringException;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\AbstractAdapter;

/**
 * Reads both webhook payload versions. Version 2 puts the message fields
 * directly in data; legacy version 1 nests them under data.email.
 */
class Adapter extends AbstractAdapter
{
    protected string $provider = 'MailerSend';

    /** Keyed by the top-level type; data.type is inconsistent (spam_complaints). */
    protected array $eventMap = [
        'activity.sent' => EmailEvent::STATUS_ACCEPTED,
        'activity.delivered' => EmailEvent::STATUS_DELIVERED,
        'activity.soft_bounced' => EmailEvent::STATUS_BOUNCED,
        'activity.hard_bounced' => EmailEvent::STATUS_BOUNCED,
        'activity.deferred' => EmailEvent::STATUS_DEFERRED,
        'activity.opened' => EmailEvent::STATUS_OPENED,
        'activity.opened_unique' => EmailEvent::STATUS_OPENED,
        'activity.clicked' => EmailEvent::STATUS_CLICKED,
        'activity.clicked_unique' => EmailEvent::STATUS_CLICKED,
        'activity.unsubscribed' => EmailEvent::STATUS_UNSUBSCRIBED,
        'activity.spam_complaint' => EmailEvent::STATUS_COMPLAINED,
        'activity.suppressed' => EmailEvent::STATUS_DROPPED,
    ];

    public function status(): ?string
    {
        return $this->eventMap[$this->string('type') ?? ''] ?? null;
    }

    public function toAddress(): ?string
    {
        return $this->string('data.recipient') ?? $this->string('data.email') ?? $this->string('data.email.recipient.email');
    }

    public function providerMessageId(): ?string
    {
        return $this->string('data.message_id') ?? $this->string('data.email.message.id');
    }

    public function occurredAt(): ?DateTimeImmutable
    {
        // Version 1 carries the activity time in data; version 2 only has the
        // top-level time, which arrives in two formats. Both parse here.
        if (! $timestamp = $this->string('data.created_at') ?? $this->string('created_at')) {
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
        return $this->get('data.meta.bounce_reason') ?? $this->get('data.morph.reason');
    }

    public function reason(): mixed
    {
        return $this->get('data.meta.bounce_reason')
            ?? $this->get('data.meta.unsubscribe_reason')
            ?? $this->get('data.meta.suppression_reason')
            ?? $this->get('data.morph.reason');
    }

    /** MailerSend documents a number; Symfony's fixtures send a string. */
    public function code(): mixed
    {
        $code = $this->get('data.meta.bounce_code');

        return is_int($code) || is_string($code) && $code !== '' ? (string) $code : null;
    }

    public function bounceType(): ?string
    {
        return match ($this->string('type')) {
            'activity.hard_bounced' => EmailEvent::BOUNCE_HARD,
            'activity.soft_bounced' => EmailEvent::BOUNCE_SOFT,
            default => null,
        };
    }

    public function clickedUrl(): ?string
    {
        return $this->status() === EmailEvent::STATUS_CLICKED
            ? $this->string('data.meta.url') ?? $this->string('data.morph.url')
            : null;
    }

    public function tags(): Collection
    {
        return collect((array) ($this->get('data.tags') ?? $this->get('data.email.tags')));
    }

    /** MailerSend's webhooks don't echo custom headers or metadata. */
    public function data(): Collection
    {
        return collect();
    }

    public function isValid(): bool
    {
        return parent::isValid() && $this->toAddress() !== '' && ! empty($this->providerMessageId());
    }

    public static function supports(array $payload): bool
    {
        return is_string($payload['type'] ?? null)
            && str_starts_with($payload['type'], 'activity.')
            && (isset($payload['data']['message_id']) || isset($payload['data']['email']['message']['id']));
    }

    public static function expand(array $payload): array
    {
        // The ping, maintenance notices, on-hold changes, and survey answers
        // aren't delivery events. Drop them without an invalid-event log.
        if (is_string($payload['type'] ?? null) && (new self($payload))->status() === null) {
            return [];
        }

        return [$payload];
    }

    protected function string(string $key): ?string
    {
        $value = $this->get($key);

        return is_string($value) ? $value : null;
    }
}
