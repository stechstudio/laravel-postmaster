<?php

namespace STS\Postmaster\Auth;

use Illuminate\Http\Request;

/**
 *
 */
class BasicHttpAuth
{
    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /**
     * BasicHttpAuth constructor.
     *
     * @param $username
     * @param $password
     */
    public function __construct( $username, $password )
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    public function __invoke( Request $request )
    {
        // Refuse unconfigured credentials. Without this guard, the loose
        // comparison `null == null` below would accept any unauthenticated
        // request when the env vars aren't set — a fail-open bug.
        if ($this->username === null || $this->username === ''
            || $this->password === null || $this->password === '') {
            return false;
        }

        return hash_equals((string) $this->username, (string) $request->getUser())
            && hash_equals((string) $this->password, (string) $request->getPassword());
    }
}