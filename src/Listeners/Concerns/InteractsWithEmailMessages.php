<?php

namespace STS\Postmaster\Listeners\Concerns;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Models\EmailMessage;

trait InteractsWithEmailMessages
{
    /**
     * A fresh instance of the configured (swappable) email message model.
     *
     * @return EmailMessage
     */
    protected function messageModel()
    {
        $class = config('postmaster.persistence.model', EmailMessage::class);

        return new $class;
    }

    /**
     * A fresh instance of the configured (swappable) email activity model.
     *
     * @return EmailActivity
     */
    protected function activityModel()
    {
        $class = config('postmaster.persistence.activity_model', EmailActivity::class);

        return new $class;
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
     * @param Model                $message
     * @param array<string, mixed> $attributes
     *
     * @return void
     */
    protected function recordActivity( Model $message, array $attributes )
    {
        if (! config('postmaster.persistence.record_events', false)) {
            return;
        }

        $model = $this->activityModel();

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
     * Append an activity entry for an address-level action (manual
     * suppression, unsuppression, sync add/clear) — no specific message
     * is involved. A no-op when persistence.record_events is off.
     *
     * @param EmailAddress         $address
     * @param array<string, mixed> $attributes
     *
     * @return void
     */
    protected function recordAddressActivity( EmailAddress $address, array $attributes )
    {
        if (! config('postmaster.persistence.record_events', false)) {
            return;
        }

        $this->activityModel()->newQuery()->create($attributes + [
            'email_address_id' => $address->getKey(),
            'occurred_at'      => $attributes['occurred_at'] ?? now(),
        ]);
    }

    /**
     * Look up the EmailAddress id for a recipient address, if one exists.
     * Returns null when no row is on file for that address (a webhook for
     * a recipient we've never sent to, perhaps).
     *
     * @param string|null $address
     *
     * @return int|null
     */
    protected function resolveAddressId( $address )
    {
        if (! $address) {
            return null;
        }

        $class = config('postmaster.persistence.address_model', EmailAddress::class);

        return (new $class)->newQuery()
            ->where('address', $class::normalize($address))
            ->value('id');
    }

    /**
     * The configured tenant column name on the email messages table.
     *
     * @return string
     */
    protected function tenantColumn()
    {
        return config('postmaster.persistence.tenant_column', 'tenant_id');
    }
}
