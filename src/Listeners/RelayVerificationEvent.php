<?php

namespace STS\Postmaster\Listeners;

use Illuminate\Support\Facades\Cache;
use STS\Postmaster\EmailEvent;

/**
 * Bridges webhook events to the postmaster:verify command, which watches from
 * a separate process and so cannot receive events directly.
 *
 * While a verification is running the command leaves a sentinel in the cache
 * naming its test message. This listener relays any event matching that
 * message back through the cache for the command to display. For every other
 * event — i.e. virtually always — it is a single cache read and nothing more.
 */
class RelayVerificationEvent
{
    /**
     * Cache key holding the message id a running verify is waiting on.
     */
    public const WATCHING_KEY = 'postmaster:verify:watching';

    /**
     * Cache key holding the events relayed back to a running verify.
     */
    public const EVENTS_KEY = 'postmaster:verify:events';

    /**
     * @param EmailEvent $event
     *
     * @return void
     */
    public function handle( EmailEvent $event )
    {
        $watching = Cache::get(self::WATCHING_KEY);

        if ($watching === null || $event->providerMessageId() !== $watching) {
            return;
        }

        $relayed = Cache::get(self::EVENTS_KEY, []);

        if (! is_array($relayed)) {
            $relayed = [];
        }

        $relayed[] = [
            'status'   => $event->status(),
            'provider' => $event->provider(),
            'at'       => ($event->occurredAt() ?? now())->format(DATE_ATOM),
        ];

        Cache::put(self::EVENTS_KEY, $relayed, now()->addMinutes(10));
    }
}
