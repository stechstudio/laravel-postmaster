<?php

namespace STS\Postmaster\Providers;

use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use STS\Postmaster\Contracts\Adapter;
use STS\Postmaster\EmailEvent;

abstract class AbstractAdapter implements Adapter
{
    protected static ?string $userAgent = null;

    /** @var array<string, mixed> */
    protected array $payload;

    /** @var array<string, mixed> */
    protected array $eventMap = [];

    protected string $provider;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * If known, provide the user-agent pattern we expect to be used by the email provider.
     */
    public static function getUserAgent(): ?string
    {
        return static::$userAgent;
    }

    public function isValid(): bool
    {
        return is_string($this->status()) && is_string($this->toAddress());
    }

    abstract public function status(): ?string;

    abstract public function providerMessageId(): ?string;

    abstract public function toAddress(): ?string;

    abstract public function occurredAt(): ?DateTimeImmutable;

    abstract public function response(): mixed;

    abstract public function reason(): mixed;

    abstract public function code(): mixed;

    /**
     * Normalized bounce severity, or null when this is not a bounce. One of
     * EmailEvent::BOUNCE_HARD | BOUNCE_SOFT | BOUNCE_BLOCK.
     */
    abstract public function bounceType(): ?string;

    /**
     * The clicked URL for a click event. The default returns null — adapters
     * for providers that expose a click URL override this.
     */
    public function clickedUrl(): ?string
    {
        return null;
    }

    /**
     * Whether this event represents a permanent failure — a hard bounce or a
     * block. These are safe to suppress; soft bounces are not.
     */
    public function isPermanent(): bool
    {
        return in_array($this->bounceType(), [
            EmailEvent::BOUNCE_HARD,
            EmailEvent::BOUNCE_BLOCK,
        ], true);
    }

    abstract public function tags(): Collection;

    abstract public function data(): Collection;

    /**
     * @param array<string, mixed> $payload
     */
    abstract public static function supports(array $payload): bool;

    /**
     * Default expansion: the payload is one event for one recipient. Adapters
     * for providers that pack per-recipient data into a single event override
     * this to fan it out (see Ses\Adapter::expand()).
     *
     * @param  array<string, mixed>             $payload
     * @return array<int, array<string, mixed>>
     */
    public static function expand(array $payload): array
    {
        return [$payload];
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function get(string $attribute): mixed
    {
        return Arr::get($this->payload, $attribute);
    }

    /**
     * Convert a unix-seconds timestamp into a UTC DateTimeImmutable. A shared
     * helper for adapters whose payload exposes the time as an int (or a
     * string they've already parsed to one).
     */
    protected static function dateFromUnix(?int $unix): ?DateTimeImmutable
    {
        return is_int($unix) ? new DateTimeImmutable('@'.$unix) : null;
    }
}
