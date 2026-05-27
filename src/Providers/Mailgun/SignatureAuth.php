<?php

namespace STS\Postmaster\Providers\Mailgun;

use Illuminate\Http\Request;

/**
 * Verifies Mailgun webhook signatures.
 *
 * Implements Mailgun's HMAC-SHA256 scheme (timestamp + token signed with a
 * shared secret). Other providers use different schemes, so each provider
 * namespace carries its own SignatureAuth.
 */
class SignatureAuth
{
    /** @var string */
    protected $signatureKey;

    /**
     * @param $signatureKey
     */
    public function __construct( $signatureKey )
    {
        $this->signatureKey = $signatureKey;
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    public function __invoke( Request $request )
    {
        if (empty($this->signatureKey)) {
            return false;
        }

        // Replay-prevention window. Mailgun's documented token validity is
        // 15 minutes (900s); we accept anything within 5 minutes, which is
        // generous enough for normal webhook latency plus tunnel/proxy
        // delays without giving a captured payload an indefinitely long
        // replay opportunity.
        if (abs(time() - (int) $request->input('signature.timestamp')) > 300) {
            return false;
        }

        return hash_equals(
            $this->buildSignature($request),
            (string) $request->input('signature.signature')
        );
    }

    /**
     * @param Request $request
     *
     * @return string
     */
    protected function buildSignature( Request $request )
    {
        return hash_hmac(
            'sha256',
            $request->input('signature.timestamp') . $request->input('signature.token'),
            $this->signatureKey
        );
    }
}
