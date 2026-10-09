<?php

namespace STS\Postmaster\Listeners;

use Closure;
use Illuminate\Mail\Events\MessageSending;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Listeners\Concerns\MakesSyntheticMessageId;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Support\OutboundMetadata;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Block-suppressed delivery: when "postmaster.block_suppressed" is on, every
 * envelope address on the suppression list is intercepted here (recorded
 * with a "blocked" status so it shows up in the app's history) and removed
 * from the message before it reaches the mail transport. The rest of the
 * recipients still get it; when none are left, the send is cancelled.
 *
 * This listens on MessageSending and returns false to cancel, which tells
 * Laravel's mailer to skip the send. Because MessageSent never fires for a
 * blocked address, this listener does the recording itself rather than
 * leaving it to RecordOutboundMessage.
 *
 * Registered after StashOutboundMetadata so any relatedTo()/forTenant()
 * metadata is already stashed by the time we record, and before the sandbox
 * interceptor so a deliberate block beats a generic intercept.
 */
class InterceptSuppressedRecipient
{
    use MakesSyntheticMessageId;

    public function __construct(protected RecordOutboundMessage $recorder)
    {
    }

    /**
     * Returns false to cancel the send; null to let it proceed.
     */
    public function handle(MessageSending $event): ?bool
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

        $message    = $event->message;
        $suppressed = $this->suppressed($message);

        if ($suppressed === []) {
            return null;
        }

        $mailer    = $event->data['mailer'] ?? config('mail.default');
        $isBlocked = fn (Address $address) => in_array(EmailAddress::normalize($address->getAddress()), $suppressed, true);

        // Some recipients can still be reached: record the suppressed ones as
        // blocked, then let the send go on to the rest. The blocked copy
        // records under a synthetic id, so it borrows the stashed metadata
        // and hands it back for the real send's row.
        if (array_filter($this->addresses($message), fn (Address $address) => ! $isBlocked($address)) !== []) {
            $metadata = OutboundMetadata::pull($message);
            $blocked  = clone $message;
            $this->keepOnly($blocked, $isBlocked);
            $this->keepOnly($message, fn (Address $address) => ! $isBlocked($address));

            OutboundMetadata::remember($blocked, $metadata);
            $this->recorder->record($blocked, $this->syntheticMessageId('blocked'), EmailEvent::STATUS_BLOCKED, $mailer);
            OutboundMetadata::remember($message, $metadata);

            return null;
        }

        $this->recorder->record($message, $this->syntheticMessageId('blocked'), EmailEvent::STATUS_BLOCKED, $mailer);

        // Cancel the send: the message is never handed to the transport.
        return false;
    }

    /**
     * Every envelope address on the message — To, Cc and Bcc.
     *
     * @return array<int, Address>
     */
    protected function addresses(Email $message): array
    {
        return [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];
    }

    /**
     * The message's envelope addresses that are on the suppression list,
     * normalized.
     *
     * @return array<int, string>
     */
    protected function suppressed(Email $message): array
    {
        $addresses = array_map(fn (Address $address) => EmailAddress::normalize($address->getAddress()), $this->addresses($message));

        if ($addresses === []) {
            return [];
        }

        return EmailAddress::model()->newQuery()
            ->whereIn('address', $addresses)
            ->where('status', EmailAddress::STATUS_SUPPRESSED)
            ->pluck('address')
            ->all();
    }

    /**
     * Drop every To, Cc and Bcc address that fails $keep, removing a header
     * left with no addresses rather than sending it empty.
     */
    protected function keepOnly(Email $message, Closure $keep): void
    {
        foreach (['To' => $message->getTo(), 'Cc' => $message->getCc(), 'Bcc' => $message->getBcc()] as $header => $addresses) {
            $message->getHeaders()->remove($header);

            if ($kept = array_values(array_filter($addresses, $keep))) {
                $message->getHeaders()->addMailboxListHeader($header, $kept);
            }
        }
    }
}
