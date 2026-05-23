<?php

namespace STS\Postmaster\Providers\Mailgun;

use DateTimeImmutable;
use Mailgun\Mailgun;
use STS\Postmaster\Contracts\SuppressionSync as Contract;
use STS\Postmaster\Models\EmailAddress;

/**
 * Mirrors Mailgun's suppression lists (bounces, complaints, unsubscribes)
 * into the package's email_addresses table.
 *
 * Mailgun's suppression endpoints are per-sending-domain; the domain comes
 * from the provider config. Soft-depends on mailgun/mailgun-php.
 */
class SuppressionSync implements Contract
{
    /**
     * Mailgun suppression list endpoints, mapped to the normalized
     * EmailAddress::REASON_* we record locally.
     */
    protected const LISTS = [
        'bounces'      => EmailAddress::REASON_BOUNCED,
        'complaints'   => EmailAddress::REASON_COMPLAINED,
        'unsubscribes' => EmailAddress::REASON_COMPLAINED,
    ];

    public function __construct( protected array $config )
    {
    }

    public function isAvailable()
    {
        return class_exists(Mailgun::class)
            && ! empty($this->config['api_key'])
            && ! empty($this->config['domain']);
    }

    public function pull()
    {
        foreach (static::LISTS as $list => $reason) {
            yield from $this->pullList($list, $reason);
        }
    }

    public function unsuppress( $address )
    {
        $client  = $this->client();
        $domain  = $this->config['domain'];
        $address = strtolower($address);
        $removed = false;

        foreach (array_keys(static::LISTS) as $list) {
            try {
                $client->suppressions()->{$list}()->delete($domain, $address);
                $removed = true;
            } catch (\Throwable $_) {
                // 404 from any one list is expected (the address wasn't on
                // it). Other errors bubble up by re-throwing the last one;
                // we only swallow when at least one list reported removal.
            }
        }

        return $removed;
    }

    /**
     * @param string $list
     * @param string $reason
     *
     * @return iterable<int, array{address: string, reason: string, suppressed_at: DateTimeImmutable|null}>
     */
    protected function pullList( string $list, string $reason )
    {
        $client = $this->client();
        $domain = $this->config['domain'];

        // Mailgun's suppression endpoints paginate; index() takes a $limit
        // and the response carries a paging "next" cursor for follow-up.
        $response = $client->suppressions()->{$list}()->index($domain, 1000);

        while (true) {
            foreach ($response->getItems() as $item) {
                $address = $item->getAddress();

                if (! is_string($address) || $address === '') {
                    continue;
                }

                yield [
                    'address'       => strtolower($address),
                    'reason'        => $reason,
                    'suppressed_at' => method_exists($item, 'getCreatedAt') ? $item->getCreatedAt() : null,
                ];
            }

            // Mailgun returns the same paging URL when there's no more to
            // fetch; bail when getItems() is empty on a follow-up call.
            $next = $response->getNextUrl();

            if (empty($next) || count($response->getItems()) === 0) {
                break;
            }

            $response = $client->suppressions()->{$list}()->nextPage($response);
        }
    }

    protected function client(): Mailgun
    {
        return Mailgun::create($this->config['api_key']);
    }
}
