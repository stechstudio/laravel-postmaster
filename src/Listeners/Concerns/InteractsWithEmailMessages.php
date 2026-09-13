<?php

namespace STS\Postmaster\Listeners\Concerns;

use Illuminate\Database\Eloquent\Model;
use Closure;
use Illuminate\Support\Facades\Cache;
use STS\Postmaster\Models\EmailMessage;
use Carbon\CarbonImmutable;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;

trait InteractsWithEmailMessages
{
    /** Serialize a submission across its send response and provider callbacks. */
    protected function withMessageLock(string $messageId, Closure $callback): mixed
    {
        return Cache::lock('postmaster:message:'.hash('sha256', $messageId), 60)->block(10,
            fn () => EmailMessage::model()->getConnection()->transaction($callback));
    }

    /**
     * Append an activity entry for a message-level event (a send, a
     * delivery/bounce/open webhook, …). Sets both email_message_id and
     * email_address_id when the message's `to_address` has a known
     * EmailAddress row, so the entry shows up on both timelines.
     *
     * A no-op when persistence.record_events is off.
     *
     * Webhook providers retry on transient failures, so the same delivery
     * notification can land twice. A row with the same message, status,
     * and occurred_at is treated as a duplicate of one already recorded —
     * the insert is skipped so the timeline never doubles up.
     *
     * @param array<string, mixed> $attributes
     */
    protected function recordActivity(Model $message, array $attributes): void
    {
        if (! config('postmaster.persistence.record_events', false)) {
            return;
        }

        $model = EmailActivity::model();
        if (isset($attributes['occurred_at'])) {
            $attributes['occurred_at'] = CarbonImmutable::parse($attributes['occurred_at'])->utc()->format('Y-m-d H:i:s.u');
        }

        $exists = $model->newQuery()
            ->where('email_message_id', $message->getKey())
            ->where('status', $attributes['status'] ?? null)
            ->where('occurred_at', $attributes['occurred_at'] ?? null)
            ->exists();

        if ($exists) {
            return;
        }

        $model->newQuery()->create($attributes + [
            'email_message_id' => $message->getKey(),
            'email_address_id' => $this->resolveAddressId($message->to_address ?? null),
        ]);
    }

    /**
     * Look up the EmailAddress id for a recipient address, if one exists.
     * Returns null when no row is on file for that address (a webhook for
     * a recipient we've never sent to, perhaps).
     */
    protected function resolveAddressId(?string $address): int|string|null
    {
        if (! $address) {
            return null;
        }

        return EmailAddress::model()->newQuery()
            ->where('address', EmailAddress::normalize($address))
            ->value('id');
    }
}
