<?php

namespace STS\Postmaster\Auth;

use Illuminate\Http\Request;

class TokenAuth
{
    /** @var string */
    protected $token;

    /** @var string  */
    protected $parameter;

    /**
     * TokenAuth constructor.
     *
     * @param        $token
     * @param string $parameter
     */
    public function __construct( $token, $parameter = 'auth' )
    {
        $this->token = $token;
        $this->parameter = $parameter;
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    public function __invoke(Request $request)
    {
        return $request->input($this->parameter) == $this->token;
    }
}