<?php

namespace STS\Postmaster\Providers\Ses;

use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\AbstractAdapter;

/**
 * Adapts Amazon SES event notifications, delivered via SNS.
 *
 * The webhook body is an SNS envelope; for a "Notification" the inner
 * "Message" field is a JSON-encoded SES event, which this adapter unwraps.
 */
class Adapter extends AbstractAdapter
{
    protected string $provider = "SES";

    protected static ?string $userAgent = "Amazon Simple Notification Service Agent";

    protected array $eventMap = [
        'Send'          => EmailEvent::STATUS_ACCEPTED,
        'Delivery'      => EmailEvent::STATUS_DELIVERED,
        'DeliveryDelay' => EmailEvent::STATUS_DEFERRED,
        'Bounce'        => EmailEvent::STATUS_BOUNCED,
        'Reject'        => EmailEvent::STATUS_DROPPED,
        'Complaint'     => EmailEvent::STATUS_COMPLAINED,
        'Open'          => EmailEvent::STATUS_OPENED,
        'Click'         => EmailEvent::STATUS_CLICKED,
    ];

    public function __construct(array $payload)
    {
        if (Arr::get($payload, 'Type') === 'Notification' && isset($payload['Message'])) {
            $message = json_decode($payload['Message'], true);

            parent::__construct(is_array($message) ? $message : $payload);

            return;
        }

        parent::__construct($payload);
    }

    /**
     * SES uses "eventType" (event publishing) or "notificationType" (the
     * older feedback notifications) for the same set of values.
     */
    protected function eventType(): ?string
    {
        return Arr::get($this->payload, 'eventType')
            ?? Arr::get($this->payload, 'notificationType');
    }

    #[\Override]
    public function status(): ?string
    {
        $type = $this->eventType();

        return $type === null ? null : Arr::get($this->eventMap, $type);
    }

    #[\Override]
    public function toAddress(): ?string
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.emailAddress')
            ?? Arr::get($this->payload, 'complaint.complainedRecipients.0.emailAddress')
            ?? Arr::get($this->payload, 'delivery.recipients.0')
            ?? Arr::get($this->payload, 'mail.destination.0');
    }

    #[\Override]
    public function occurredAt(): ?\DateTimeImmutable
    {
        $timestamp = Arr::get($this->payload, 'mail.timestamp');

        return static::dateFromUnix($timestamp ? strtotime($timestamp) : null);
    }

    #[\Override]
    public function providerMessageId(): ?string
    {
        return Arr::get($this->payload, 'mail.messageId');
    }

    #[\Override]
    public function tags(): \Illuminate\Support\Collection
    {
        return collect(array_keys((array) Arr::get($this->payload, 'mail.tags')));
    }

    #[\Override]
    public function data(): \Illuminate\Support\Collection
    {
        return collect((array) Arr::get($this->payload, 'mail.tags'));
    }

    #[\Override]
    public function response(): mixed
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.diagnosticCode');
    }

    #[\Override]
    public function code(): mixed
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.status');
    }

    #[\Override]
    public function reason(): mixed
    {
        return Arr::get($this->payload, 'bounce.bounceSubType')
            ?? Arr::get($this->payload, 'complaint.complaintFeedbackType');
    }

    #[\Override]
    public function bounceType(): ?string
    {
        if ($this->status() !== EmailEvent::STATUS_BOUNCED) {
            return null;
        }

        return Arr::get($this->payload, 'bounce.bounceType') === 'Permanent'
            ? EmailEvent::BOUNCE_HARD
            : EmailEvent::BOUNCE_SOFT;
    }

    #[\Override]
    public function clickedUrl(): ?string
    {
        return Arr::get($this->payload, 'click.link');
    }

    #[\Override]
    public static function supports(array $payload): bool
    {
        return array_key_exists('eventType', $payload)
            || array_key_exists('notificationType', $payload)
            || Arr::get($payload, 'Type') === 'Notification';
    }

    /**
     * SES packs per-recipient data into a single event for delivery,
     * bounce, and complaint notifications — fan each recipient out into
     * its own event so per-row correlation finds the right `to_address`.
     *
     * @param array $payload
     *
     * @return array<int, array>
     */
    #[\Override]
    public static function expand(array $payload): array
    {
        $event = $payload;

        // Unwrap the SNS Notification envelope so we can read the event
        // type. The constructor will be passed the unwrapped form and skip
        // its own unwrap step.
        if (Arr::get($event, 'Type') === 'Notification' && isset($event['Message'])) {
            $decoded = json_decode($event['Message'], true);
            if (! is_array($decoded)) {
                return [$payload];
            }
            $event = $decoded;
        }

        $type = Arr::get($event, 'eventType') ?? Arr::get($event, 'notificationType');

        $expanders = [
            'Delivery'  => 'delivery.recipients',
            'Bounce'    => 'bounce.bouncedRecipients',
            'Complaint' => 'complaint.complainedRecipients',
        ];

        if (! isset($expanders[$type])) {
            return [$payload];
        }

        $path       = $expanders[$type];
        $recipients = Arr::get($event, $path, []);

        if (count($recipients) <= 1) {
            return [$payload];
        }

        $expanded = [];

        foreach ($recipients as $recipient) {
            // Delivery recipients are bare strings; Bounce / Complaint
            // entries are objects with emailAddress + diagnostic fields.
            // Either way, each fanned event keeps just one entry.
            $clone = $event;
            Arr::set($clone, $path, [$recipient]);
            $expanded[] = $clone;
        }

        return $expanded;
    }
}
