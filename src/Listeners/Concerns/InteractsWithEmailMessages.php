<?php

namespace STS\Postmaster\Listeners\Concerns;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Models\EmailMessage;

trait InteractsWithEmailMessages
{
    /**
     * A fresh instance of the configured (swappable) email message model.
     *
     * @return Model
     */
    protected function messageModel()
    {
        $class = config('postmaster.persistence.model', EmailMessage::class);

        return new $class;
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
