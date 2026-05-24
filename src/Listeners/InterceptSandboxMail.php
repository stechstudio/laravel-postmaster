<?php

namespace STS\Postmaster\Listeners;

use Illuminate\Mail\Events\MessageSending;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Listeners\Concerns\MakesSyntheticMessageId;

/**
 * Sandbox delivery: when "postmaster.delivery" is "sandbox", every outbound
 * email is intercepted here, recorded with a "sandbox" status (so it shows up
 * in the app's email history), and then suppressed — the send is cancelled and
 * nothing reaches the mail transport.
 *
 * This listens on MessageSending and returns false, which tells Laravel's
 * mailer to skip the send. Because MessageSent never fires for a cancelled
 * send, this listener does the recording itself rather than leaving it to
 * RecordOutboundMessage.
 *
 * Registered after StashOutboundMetadata so any relatedTo()/forTenant()
 * metadata is already stashed by the time we record.
 */
class InterceptSandboxMail
{
    use MakesSyntheticMessageId;

    public function __construct( protected RecordOutboundMessage $recorder )
    {
    }

    /**
     * @param MessageSending $event
     *
     * @return bool|null False to cancel the send; null to let it proceed.
     */
    public function handle( MessageSending $event )
    {
        if (config('postmaster.delivery') !== 'sandbox') {
            return null;
        }

        // Record only when persistence is on — otherwise sandbox simply
        // suppresses the send, with nothing to show in the app.
        if (config('postmaster.persistence.enabled')) {
            $this->recorder->record(
                $event->message,
                $this->syntheticMessageId('sandboxed'),
                EmailEvent::STATUS_SANDBOXED
            );
        }

        // Cancel the send: the message is never handed to the transport.
        return false;
    }
}
