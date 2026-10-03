<?php

namespace STS\Postmaster\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * Removes every X-Postmaster-* header before the provider sees the message.
 * StashOutboundMetadata moves the headers it knows into the stash; this
 * catches the rest, and all of them when persistence is off and the stash
 * listener doesn't run. Registered after every Postmaster listener that
 * reads those headers.
 */
class StripTrackingHeaders
{
    public function handle(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();

        foreach ($headers->all() as $header) {
            if (str_starts_with(strtolower($header->getName()), 'x-postmaster-')) {
                $headers->remove($header->getName());
            }
        }
    }
}
