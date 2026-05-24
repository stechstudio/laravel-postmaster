<?php

namespace STS\Postmaster\Listeners\Concerns;

use Illuminate\Support\Str;

/**
 * Generates a unique, unmistakably-synthetic message id for an interceptor
 * that records a "never actually sent" outbound message (a sandboxed send,
 * a suppressed-recipient block). The supplied prefix keeps the id from
 * colliding with a real provider id or being matched by an inbound webhook —
 * "sandboxed-" / "blocked-" are the conventional choices.
 */
trait MakesSyntheticMessageId
{
    /**
     * @param string $prefix Trailing dash supplied automatically.
     *
     * @return string
     */
    protected function syntheticMessageId( string $prefix )
    {
        return $prefix.'-'.Str::uuid()->toString();
    }
}
