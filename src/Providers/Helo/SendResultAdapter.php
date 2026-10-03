<?php

namespace STS\Postmaster\Providers\Helo;

use STS\Postmaster\EmailEvent;

/** Normalize the API's submission result through the ordinary event pipeline. */
class SendResultAdapter extends Adapter
{
    public function status(): ?string
    {
        $suppressed = array_map('strtolower', $this->payload['suppressions'] ?? []);

        if (in_array(strtolower($this->toAddress() ?? ''), $suppressed, true)) {
            return EmailEvent::STATUS_DROPPED;
        }

        return $this->get('status') === 'delayed' ? EmailEvent::STATUS_DEFERRED : EmailEvent::STATUS_ACCEPTED;
    }

    public function reason(): mixed
    {
        return $this->status() === EmailEvent::STATUS_DROPPED ? 'suppressed' : null;
    }
}
