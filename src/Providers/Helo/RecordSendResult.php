<?php

namespace STS\Postmaster\Providers\Helo;

use Illuminate\Mail\Events\MessageSent;
use STS\Postmaster\Provider;

class RecordSendResult
{
    public function handle(MessageSent $event): void
    {
        $header = $event->message->getHeaders()->get(Transport::RESULT_HEADER);
        if ($header === null) {
            return;
        }

        $result = json_decode($header->getBodyAsString(), true, flags: JSON_THROW_ON_ERROR);
        $event->message->getHeaders()->remove(Transport::RESULT_HEADER);
        $payloads = [];
        $suppressed = array_map('strtolower', $result['suppressions'] ?? []);
        foreach ($event->sent->getEnvelope()->getRecipients() as $recipient) {
            if ($result['status'] !== 'delayed' && ! in_array(strtolower($recipient->getAddress()), $suppressed, true)) {
                continue;
            }
            $payloads[] = $result + [
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
