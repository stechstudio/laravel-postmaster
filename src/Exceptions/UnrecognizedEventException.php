<?php

namespace STS\Postmaster\Exceptions;

class UnrecognizedEventException extends \Exception
{
    protected $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;

        parent::__construct("Email event payload is not supported by any registered adapter");
    }

    public function getPayload()
    {
        return $this->payload;
    }
}