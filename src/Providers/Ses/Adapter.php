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
    public function status()
    {
        return Arr::get($this->eventMap, $this->eventType());
    }

    /**
     * @return string|null
     */
    public function toAddress()
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.emailAddress')
            ?? Arr::get($this->payload, 'complaint.complainedRecipients.0.emailAddress')
            ?? Arr::get($this->payload, 'mail.destination.0');
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function occurredAt()
    {
        $timestamp = Arr::get($this->payload, 'mail.timestamp');

        return static::dateFromUnix($timestamp ? strtotime($timestamp) : null);
    }

    /**
     * @return string|null
     */
    public function providerMessageId()
    {
        return Arr::get($this->payload, 'mail.messageId');
    }

    /**
     * @return Collection
     */
    public function tags()
    {
        return collect(array_keys((array) Arr::get($this->payload, 'mail.tags')));
    }

    /**
     * @return Collection
     */
    public function data()
    {
        return collect((array) Arr::get($this->payload, 'mail.tags'));
    }

    /**
     * @return mixed
     */
    public function response()
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.diagnosticCode');
    }

    /**
     * @return mixed
     */
    public function code()
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.status');
    }

    /**
     * @return mixed
     */
    public function reason()
    {
        return Arr::get($this->payload, 'bounce.bounceSubType')
            ?? Arr::get($this->payload, 'complaint.complaintFeedbackType');
    }

    /**
     * @return string|null
     */
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
    public function clickedUrl()
    {
        return Arr::get($this->payload, 'click.link');
    }

    /**
     * @param array $payload
     *
     * @return bool
     */
    public static function supports( array $payload )
    {
        return array_key_exists('eventType', $payload)
            || array_key_exists('notificationType', $payload)
            || Arr::get($payload, 'Type') === 'Notification';
    }
}
