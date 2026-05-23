<?php

namespace STS\Postmaster\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Str;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Postmaster;

/**
 * Block-suppressed delivery: when "postmaster.block_suppressed" is on, any
 * outbound email to an address on the suppression list is intercepted here
 * (recorded with a "blocked" status so it shows up in the app's history),
 * and the send is cancelled before the message ever reaches the mail
 * transport.
 *
 * This listens on MessageSending and returns false, which tells Laravel's
 * mailer to skip the send. Because MessageSent never fires for a cancelled
 * send, this listener does the recording itself rather than leaving it to
 * RecordOutboundMessage.
 *
 * Registered after StashOutboundMetadata so any relatedTo()/forTenant()
 * metadata is already stashed by the time we record, and before the sandbox
 * interceptor so a deliberate block beats a generic intercept.
 */
class InterceptSuppressedRecipient
{
    public function __construct(
        protected Postmaster $postmaster,
        protected RecordOutboundMessage $recorder,
    ) {
    }

    /**
     * @param MessageSending $event
     *
     * @return bool|null False to cancel the send; null to let it proceed.
     */
    public function handle( MessageSending $event )
    {
        if (! config('postmaster.block_suppressed')) {
            return null;
        }

        // Block-suppressed depends on the suppression list, which lives in
        // the persistence layer. With persistence off, there's nothing to
        // check against — let the send proceed.
        if (! config('postmaster.persistence.enabled')) {
            return null;
        }

        $to = $event->message->getTo();

        if (empty($to)) {
            return null;
        }

        $address = $to[0]->getAddress();

        if (! $this->postmaster->isSuppressed($address)) {
            return null;
        }

        $this->recorder->record(
            $event->message,
            $this->syntheticMessageId(),
            EmailEvent::STATUS_BLOCKED
        );

        // Cancel the send: the message is never handed to the transport.
        return false;
    }

    /**
     * A unique, unmistakably-synthetic message id for a blocked send. The
     * "blocked-" prefix keeps it from ever colliding with a real provider
     * id or being matched by an inbound webhook.
     *
     * @return string
     */
    protected function syntheticMessageId()
    {
        return 'blocked-'.Str::uuid()->toString();
    }
}
