<?php

namespace STS\Postmaster\Providers\Helo;

use Illuminate\Mail\Events\MessageSent;
use STS\HeloEmail\HeloResult;
use STS\Postmaster\Provider;

class RecordSendResult
{
    public function handle(MessageSent $event): void
    {
        if (! class_exists(HeloResult::class) || ! $result = HeloResult::from($event->sent)) {
            return;
        }

        $payloads = [];
        $suppressed = array_map('strtolower', $result->suppressions);

        foreach ($event->sent->getEnvelope()->getRecipients() as $recipient) {
            if (! $result->isDelayed() && ! in_array(strtolower($recipient->getAddress()), $suppressed, true)) {
                continue;
            }

            $payloads[] = $result->toArray() + [
                'recipient' => $recipient->getAddress(),
                'timestamp' => now()->toISOString(),
            ];
        }

        if ($payloads === []) {
            return;
        }

        // Runs after RecordOutboundMessage, so model links and sandbox
        // releases are already in place before these events dispatch.
        (new Provider('helo', SendResultAdapter::class, fn () => true))
            ->adapt($payloads)->dispatch();
    }
}
