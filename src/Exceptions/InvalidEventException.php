<?php

namespace STS\Postmaster\Exceptions;

class InvalidEventException extends \Exception
{
    /** @var array<string, mixed> */
    protected array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;

        parent::__construct("Email event payload could not be parsed into a valid event");
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
