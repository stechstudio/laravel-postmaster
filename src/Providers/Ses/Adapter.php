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
    /**
     * @var string
     */
    protected $provider = "SES";

    /**
     * @var string
     */
    protected static $userAgent = "Amazon Simple Notification Service Agent";

    /**
     * @var array
     */
    protected $eventMap = [
        'Send'          => EmailEvent::STATUS_ACCEPTED,
        'Delivery'      => EmailEvent::STATUS_DELIVERED,
        'DeliveryDelay' => EmailEvent::STATUS_DEFERRED,
        'Bounce'        => EmailEvent::STATUS_BOUNCED,
        'Reject'        => EmailEvent::STATUS_DROPPED,
        'Complaint'     => EmailEvent::STATUS_COMPLAINED,
        'Open'          => EmailEvent::STATUS_OPENED,
        'Click'         => EmailEvent::STATUS_CLICKED,
    ];

    /**
     * @param array $payload
     */
    public function __construct( $payload )
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
     *
     * @return string|null
     */
    protected function eventType()
    {
        return Arr::get($this->payload, 'eventType')
            ?? Arr::get($this->payload, 'notificationType');
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function status()
    {
        return Arr::get($this->eventMap, $this->eventType());
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function toAddress()
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.emailAddress')
            ?? Arr::get($this->payload, 'complaint.complainedRecipients.0.emailAddress')
            ?? Arr::get($this->payload, 'delivery.recipients.0')
            ?? Arr::get($this->payload, 'mail.destination.0');
    }

    /**
     * @return DateTimeImmutable|null
     */
    #[\Override]
    public function occurredAt()
    {
        $timestamp = Arr::get($this->payload, 'mail.timestamp');

        return static::dateFromUnix($timestamp ? strtotime($timestamp) : null);
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function providerMessageId()
    {
        return Arr::get($this->payload, 'mail.messageId');
    }

    /**
     * @return Collection
     */
    #[\Override]
    public function tags()
    {
        return collect(array_keys((array) Arr::get($this->payload, 'mail.tags')));
    }

    /**
     * @return Collection
     */
    #[\Override]
    public function data()
    {
        return collect((array) Arr::get($this->payload, 'mail.tags'));
    }

    /**
     * @return mixed
     */
    #[\Override]
    public function response()
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.diagnosticCode');
    }

    /**
     * @return mixed
     */
    #[\Override]
    public function code()
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.status');
    }

    /**
     * @return mixed
     */
    #[\Override]
    public function reason()
    {
        return Arr::get($this->payload, 'bounce.bounceSubType')
            ?? Arr::get($this->payload, 'complaint.complaintFeedbackType');
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function bounceType()
    {
        if ($this->status() !== EmailEvent::STATUS_BOUNCED) {
            return null;
        }

        return Arr::get($this->payload, 'bounce.bounceType') === 'Permanent'
            ? EmailEvent::BOUNCE_HARD
            : EmailEvent::BOUNCE_SOFT;
    }

    /**
     * @return string|null
     */
    #[\Override]
    public function clickedUrl()
    {
        return Arr::get($this->payload, 'click.link');
    }

    /**
     * @param array $payload
     *
     * @return bool
     */
    #[\Override]
    public static function supports( array $payload )
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
    public static function expand( array $payload )
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
