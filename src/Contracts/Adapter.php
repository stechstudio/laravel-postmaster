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
    /**
     * @return bool
     */
    public function isValid();

    /**
     * @return string
     */
    public function provider();

    /**
     * The normalized lifecycle status this webhook represents — one of the
     * EmailEvent::STATUS_* constants, or null if the provider's event type
     * does not map to anything we recognise.
     *
     * @return string|null
     */
    public function status();

    /**
     * The id the provider assigned to the original message.
     *
     * @return string|null
     */
    public function providerMessageId();

    /**
     * The email address this event is about.
     *
     * @return string|null
     */
    public function toAddress();

    /**
     * When the event happened, per the provider. Null when the provider did
     * not supply a usable timestamp.
     *
     * @return DateTimeImmutable|null
     */
    public function occurredAt();

    /**
     * @return mixed
     */
    public function response();

    /**
     * @return mixed
     */
    public function reason();

    /**
     * @return mixed
     */
    public function code();

    /**
     * Normalized bounce severity, or null when this is not a bounce.
     *
     * @return string|null
     */
    public function bounceType();

    /**
     * The clicked URL for a click event, or null for any other event type
     * (or providers that don't expose one).
     *
     * @return string|null
     */
    public function clickedUrl();

    /**
     * @return bool
     */
    public function isPermanent();

    /**
     * @return Collection
     */
    public function tags();

    /**
     * @return Collection
     */
    public function data();

    /**
     * @return array
     */
    public function payload();
}
