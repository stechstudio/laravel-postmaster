<?php

namespace STS\Postmaster\Support;

/**
 * The badge colour for a status, wherever one is shown.
 *
 * Both the server-rendered badge and the live activity feed's JavaScript read
 * this map. They used to keep a copy each, and the copies had drifted: the
 * feed knew nothing about the address-level statuses, so a suppression landing
 * live drew grey where the very same row drew red after a reload.
 *
 * Covers lifecycle statuses (EmailEvent::STATUS_*) and the address-level ones
 * (EmailAddress / EmailActivity), because the activity stream interleaves both.
 */
final class StatusTone
{
    /**
     * Status => tone. Anything unmapped is muted, so a provider status we
     * don't model yet renders quietly rather than not at all.
     *
     * @var array<string, string>
     */
    protected const array TONES = [
        'delivered'    => 'ok',
        'active'       => 'ok',
        'opened'       => 'info',
        'clicked'      => 'info',
        'sent'         => 'muted',
        'accepted'     => 'muted',
        'logged'       => 'muted',
        'captured'     => 'muted',
        'unsuppressed' => 'muted',
        'sandboxed'    => 'warn',
        'blocked'      => 'warn',
        'deferred'     => 'warn',
        'bounced'      => 'bad',
        'dropped'      => 'bad',
        'complained'   => 'bad',
        'suppressed'   => 'bad',
    ];

    public static function for(?string $status): string
    {
        return self::TONES[$status] ?? 'muted';
    }

    /**
     * The whole map, for the feed's JavaScript to resolve tones client-side.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::TONES;
    }
}
