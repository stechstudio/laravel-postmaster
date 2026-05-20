<?php

namespace STS\EmailEvents\Listeners\Concerns;

use Illuminate\Database\Eloquent\Model;
use STS\EmailEvents\Models\EmailMessage;

trait InteractsWithEmailMessages
{
    /**
     * A fresh instance of the configured (swappable) email message model.
     *
     * @return Model
     */
    protected function messageModel()
    {
        $class = config('email-events.persistence.model', EmailMessage::class);

        return new $class;
    }

    /**
     * The configured tenant column name on the email messages table.
     *
     * @return string
     */
    protected function tenantColumn()
    {
        return config('email-events.persistence.tenant_column', 'tenant_id');
    }
}
