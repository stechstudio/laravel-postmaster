<?php

namespace STS\Postmaster\Providers\Postmark;

use DateTimeImmutable;
use Postmark\PostmarkClient;
use STS\Postmaster\Contracts\SuppressionSync as Contract;
use STS\Postmaster\Models\EmailAddress;

/**
 * Mirrors Postmark's suppression list into the package's email_addresses
 * table. Operates against the "outbound" message stream by default — apps
 * sending transactional mail through a custom stream can configure the
 * stream name in their postmaster provider config.
 *
 * Soft-depends on wildbit/postmark-php.
 */
class SuppressionSync implements Contract
{
    /**
     * Postmark's suppression-reason taxonomy mapped to our normalized values.
     * Anything we don't recognize falls through to REASON_BOUNCED.
     */
    protected const REASON_MAP = [
        'HardBounce'         => EmailAddress::REASON_BOUNCED,
        'SoftBounce'         => EmailAddress::REASON_BOUNCED,
        'BadEmailAddress'    => EmailAddress::REASON_BOUNCED,
        'ManualSuppression'  => EmailAddress::REASON_MANUAL,
        'SpamComplaint'      => EmailAddress::REASON_COMPLAINED,
        'SpamNotification'   => EmailAddress::REASON_COMPLAINED,
        'Blocked'            => EmailAddress::REASON_BOUNCED,
        'Unsubscribe'        => EmailAddress::REASON_COMPLAINED,
    ];

    public function __construct( protected array $config )
    {
    }

    public function isAvailable()
    {
        return class_exists(PostmarkClient::class) && ! empty($this->config['server_token']);
    }

    public function pull()
    {
        $client = $this->client();
        $stream = $this->config['message_stream'] ?? 'outbound';

        // PostmarkClient::getSuppressions returns a DynamicResponseModel
        // whose top-level "Suppressions" property is the array we want.
        $response = $client->getSuppressions($stream);

        foreach ($response->Suppressions ?? [] as $entry) {
            $address = $entry->EmailAddress ?? null;

            if (! is_string($address) || $address === '') {
                continue;
            }

            yield [
                'address'       => strtolower($address),
                'reason'        => self::REASON_MAP[$entry->SuppressionReason ?? ''] ?? EmailAddress::REASON_BOUNCED,
                'suppressed_at' => isset($entry->CreatedAt) ? new DateTimeImmutable($entry->CreatedAt) : null,
            ];
        }
    }

    public function unsuppress( $address )
    {
        $client = $this->client();
        $stream = $this->config['message_stream'] ?? 'outbound';

        $response = $client->deleteSuppressions($stream, [strtolower($address)]);

        // Postmark returns per-address status; "Suppressed" means the
        // unsuppress was *requested* (it can take a moment to propagate).
        $entries = $response->Suppressions ?? [];

        foreach ($entries as $entry) {
            if (isset($entry->Status) && $entry->Status !== 'Failed') {
                return true;
            }
        }

        return false;
    }

    protected function client(): PostmarkClient
    {
        return new PostmarkClient($this->config['server_token']);
    }
}
