<?php

namespace STS\Postmaster\Tests\Stubs;

use STS\Postmaster\Contracts\SuppressionSync;

/**
 * A test stand-in for a provider's SuppressionSync that returns canned
 * data — used by the postmaster:sync reconciliation tests without
 * needing any real provider SDK installed.
 */
class FakeSync implements SuppressionSync
{
    public static array $remote = [];

    public static array $unsuppressed = [];

    public static bool $available = true;

    public function __construct( protected array $config = [] )
    {
    }

    public function isAvailable(): bool
    {
        return static::$available;
    }

    public function pull(): iterable
    {
        foreach (static::$remote as $address => $reason) {
            yield [
                'address'       => strtolower($address),
                'reason'        => $reason,
                'suppressed_at' => null,
            ];
        }
    }

    public function unsuppress(string $address): bool
    {
        static::$unsuppressed[] = strtolower($address);

        return true;
    }

    public static function reset(): void
    {
        static::$remote       = [];
        static::$unsuppressed = [];
        static::$available    = true;
    }
}
