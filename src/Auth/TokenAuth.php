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
        // Refuse an unconfigured token. Without this guard, the loose
        // comparison `null == null` below would accept any unauthenticated
        // request when POSTMASTER_AUTH_TOKEN isn't set — a fail-open bug.
        if ($this->token === null || $this->token === '') {
            return false;
        }

        return hash_equals((string) $this->token, (string) $request->input($this->parameter));
    }
}