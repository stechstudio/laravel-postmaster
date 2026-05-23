<?php

namespace STS\Postmaster\Listeners\Concerns;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailMessageEvent;

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
     * A fresh instance of the configured (swappable) timeline event model.
     *
     * @return EmailMessageEvent
     */
    protected function eventModel()
    {
        $class = config('postmaster.persistence.event_model', EmailMessageEvent::class);

        return new $class;
    }

    /**
     * Append a timeline event to the given message, when timeline recording
     * is enabled. A no-op otherwise.
     *
     * Webhook providers retry on transient failures, so the same delivery
     * notification can land twice. A row with the same message, status, and
     * occurred_at is treated as a duplicate of one already recorded — the
     * insert is skipped so the timeline never doubles up.
     *
     * @param Model                $message
     * @param array<string, mixed> $attributes
     *
     * @return void
     */
    protected function recordEvent( Model $message, array $attributes )
    {
        if (! config('postmaster.persistence.record_events', false)) {
            return;
        }

        $model = $this->eventModel();

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
        ]);
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
