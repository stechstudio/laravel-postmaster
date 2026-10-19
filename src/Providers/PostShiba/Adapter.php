<?php

namespace STS\Postmaster\Providers\PostShiba;

use Illuminate\Support\Collection;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Providers\SendGrid\Adapter as SendGridAdapter;

/**
 * PostShiba posts events in SendGrid's Event Webhook format, so this reuses
 * the SendGrid adapter and changes only what differs: the message id, the
 * unsubscribe event, and test events.
 */
class Adapter extends SendGridAdapter
{
    protected string $provider = 'PostShiba';

    protected static ?string $userAgent = null;

    protected array $eventMap = [
        'processed'   => EmailEvent::STATUS_ACCEPTED,
        'deferred'    => EmailEvent::STATUS_DEFERRED,
        'delivered'   => EmailEvent::STATUS_DELIVERED,
        'bounce'      => EmailEvent::STATUS_BOUNCED,
        'dropped'     => EmailEvent::STATUS_DROPPED,
        'spamreport'  => EmailEvent::STATUS_COMPLAINED,
        'unsubscribe' => EmailEvent::STATUS_UNSUBSCRIBED,
    ];

    /**
     * Match on smtp-id, not sg_message_id. The PostShiba transport stores the
     * API's message_id on the sent message, and smtp-id is that same id in
     * angle brackets. sg_message_id is a different value on real sends.
     */
    #[\Override]
    public function providerMessageId(): ?string
    {
        $smtpId = $this->get('smtp-id');

        return is_string($smtpId) && trim($smtpId, '<>') !== '' ? trim($smtpId, '<>') : null;
    }

    #[\Override]
    public function isValid(): bool
    {
        return parent::isValid() && $this->providerMessageId() !== null;
    }

    /** The unique args set at send time. PostShiba also copies them onto the event. */
    #[\Override]
    public function data(): Collection
    {
        return collect((array) $this->get('unique_args'));
    }

    #[\Override]
    public static function supports(array $payload): bool
    {
        return str_starts_with((string) ($payload['sg_event_id'] ?? ''), 'capsule-');
    }

    /** The dashboard's "Send test event" posts a sample. Accept it and record nothing. */
    #[\Override]
    public static function expand(array $payload): array
    {
        return ($payload['test'] ?? false) === true ? [] : [$payload];
    }
}
