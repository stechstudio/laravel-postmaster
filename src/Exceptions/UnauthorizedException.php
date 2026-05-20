<?php

namespace STS\Postmaster\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthorizedException extends HttpException
{
    /**
     * @var Request
     */
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;

        parent::__construct(403, "Unauthorized email event webhook submission");
    }

    public function getRequest()
    {
        return $this->request;
    }
}