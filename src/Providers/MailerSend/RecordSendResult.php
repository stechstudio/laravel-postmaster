<?php

namespace STS\Postmaster\Providers\MailerSend;

use Illuminate\Mail\Events\MessageSent;
use STS\Postmaster\Listeners\RecordOutboundMessage;
use STS\Postmaster\Provider;
use Symfony\Component\Mime\Message;

/**
 * MailerSend accepts a send but skips suppressed recipients, and lists them
 * in the response. mailersend/laravel-driver copies that response to an
 * X-MailerSend-Body header. Record each skipped recipient as dropped.
 */
class RecordSendResult
{
    public function handle(MessageSent $event): void
    {
        $original = $event->sent->getOriginalMessage();
        $body = $original instanceof Message ? $original->getHeaders()->get('X-MailerSend-Body')?->getBodyAsString() : null;
        $warnings = json_decode((string) $body, true)['warnings'] ?? null;

        if (! is_array($warnings)) {
            return;
        }

        // With every recipient suppressed there is no MailerSend id, so use
        // whatever id the outbound record got.
        $messageId = app(RecordOutboundMessage::class)->providerMessageId($event->sent);
        $payloads = [];

        foreach ($warnings as $warning) {
            if (! in_array($warning['type'] ?? null, ['SOME_SUPPRESSED', 'ALL_SUPPRESSED'], true)) {
                continue;
            }

            foreach ((array) ($warning['recipients'] ?? []) as $recipient) {
                $payloads[] = [
                    'type' => 'activity.suppressed',
                    'created_at' => now()->toISOString(),
                    'data' => [
                        'message_id' => $messageId,
                        'recipient' => $recipient['email'] ?? null,
                        'meta' => ['suppression_reason' => $recipient['reasons'][0] ?? 'suppressed'],
                    ],
                ];
            }
        }

        if ($payloads === []) {
            return;
        }

        // Runs after RecordOutboundMessage, so the rows to update exist.
        (new Provider('mailersend', Adapter::class, fn () => true))->adapt($payloads)->dispatch();
    }
}
