<?php

namespace STS\Postmaster\Providers\Ses;

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
        'Send'          => EmailEvent::EMAIL_ACCEPTED,
        'Delivery'      => EmailEvent::EVENT_DELIVERED,
        'DeliveryDelay' => EmailEvent::EVENT_DEFERRED,
        'Bounce'        => EmailEvent::EVENT_BOUNCED,
        'Reject'        => EmailEvent::EVENT_DROPPED,
        'Complaint'     => EmailEvent::EVENT_COMPLAINED,
        'Open'          => EmailEvent::EVENT_OPENED,
        'Click'         => EmailEvent::EVENT_CLICKED,
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
     * @return mixed
     */
    public function getAction()
    {
        return Arr::get($this->eventMap, $this->eventType());
    }

    /**
     * @return mixed
     */
    public function getRecipient()
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.emailAddress')
            ?? Arr::get($this->payload, 'complaint.complainedRecipients.0.emailAddress')
            ?? Arr::get($this->payload, 'mail.destination.0');
    }

    /**
     * @return int|null
     */
    public function getTimestamp()
    {
        $timestamp = Arr::get($this->payload, 'mail.timestamp');

        return $timestamp ? strtotime($timestamp) : null;
    }

    /**
     * @return mixed
     */
    public function getMessageId()
    {
        return Arr::get($this->payload, 'mail.messageId');
    }

    /**
     * @return Collection
     */
    public function getTags()
    {
        return collect(array_keys((array) Arr::get($this->payload, 'mail.tags')));
    }

    /**
     * @return Collection
     */
    public function getData()
    {
        return collect((array) Arr::get($this->payload, 'mail.tags'));
    }

    /**
     * @return mixed
     */
    public function getResponse()
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.diagnosticCode');
    }

    /**
     * @return mixed
     */
    public function getCode()
    {
        return Arr::get($this->payload, 'bounce.bouncedRecipients.0.status');
    }

    /**
     * @return mixed
     */
    public function getReason()
    {
        return Arr::get($this->payload, 'bounce.bounceSubType')
            ?? Arr::get($this->payload, 'complaint.complaintFeedbackType');
    }

    /**
     * @return string|null
     */
    public function getBounceType()
    {
        if ($this->getAction() !== EmailEvent::EVENT_BOUNCED) {
            return null;
        }

        return Arr::get($this->payload, 'bounce.bounceType') === 'Permanent'
            ? EmailEvent::BOUNCE_HARD
            : EmailEvent::BOUNCE_SOFT;
    }

    /**
     * @return string|null
     */
    public function getUrl()
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
