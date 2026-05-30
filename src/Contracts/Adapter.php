<?php

namespace STS\Postmaster\Contracts;

use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * The instance API every provider adapter exposes. EmailEvent and the rest
 * of the package depend on this contract rather than on AbstractAdapter.
 */
interface Adapter
{
    public function isValid(): bool;

    public function provider(): string;

    /**
     * The normalized lifecycle status this webhook represents — one of the
     * EmailEvent::STATUS_* constants, or null if the provider's event type
     * does not map to anything we recognise.
     */
    public function status(): ?string;

    /**
     * The id the provider assigned to the original message.
     */
    public function providerMessageId(): ?string;

    /**
     * The email address this event is about.
     */
    public function toAddress(): ?string;

    /**
     * When the event happened, per the provider. Null when the provider did
     * not supply a usable timestamp.
     */
    public function occurredAt(): ?DateTimeImmutable;

    public function response(): mixed;

    public function reason(): mixed;

    public function code(): mixed;

    /**
     * Normalized bounce severity, or null when this is not a bounce.
     */
    public function bounceType(): ?string;

    /**
     * The clicked URL for a click event, or null for any other event type
     * (or providers that don't expose one).
     */
    public function clickedUrl(): ?string;

    public function isPermanent(): bool;

    public function tags(): Collection;

    public function data(): Collection;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * Expand a single inbound payload into one payload per recipient, for
     * providers that pack multiple recipients into a single event (SES's
     * delivery.recipients array, for one). The default returns the payload
     * unchanged in a one-element list.
     *
     * @param  array<string, mixed>             $payload
     * @return array<int, array<string, mixed>>
     */
    public static function expand(array $payload): array;

    /**
     * The user-agent pattern this adapter expects, used by UserAgentAuth
     * to gate webhooks by provider UA. Null when the adapter doesn't
     * advertise one.
     */
    public static function getUserAgent(): ?string;
}
