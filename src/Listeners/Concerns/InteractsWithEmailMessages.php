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

        $this->eventModel()->newQuery()->create($attributes + [
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
