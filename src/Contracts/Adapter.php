<?php

namespace STS\Postmaster\Contracts;

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
    public function getProvider();

    /**
     * @return string|null
     */
    public function getAction();

    /**
     * @return string|null
     */
    public function getMessageId();

    /**
     * @return string|null
     */
    public function getRecipient();

    /**
     * @return int|null
     */
    public function getTimestamp();

    /**
     * @return \DateTimeImmutable|null
     */
    public function getDate();

    /**
     * @return mixed
     */
    public function getResponse();

    /**
     * @return mixed
     */
    public function getReason();

    /**
     * @return mixed
     */
    public function getCode();

    /**
     * Normalized bounce severity, or null when this is not a bounce.
     *
     * @return string|null
     */
    public function getBounceType();

    /**
     * @return bool
     */
    public function isPermanent();

    /**
     * @return Collection
     */
    public function getTags();

    /**
     * @return Collection
     */
    public function getData();

    /**
     * @return array
     */
    public function getPayload();
}
