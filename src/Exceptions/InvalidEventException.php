<?php

namespace STS\Postmaster\Exceptions;

class InvalidEventException extends \Exception
{
    protected $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;

        parent::__construct("Email event payload could not be parsed into a valid event");
    }

    public function getPayload()
    {
        return $this->payload;
    }
}
