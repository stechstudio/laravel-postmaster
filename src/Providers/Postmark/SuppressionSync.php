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

    public function isAvailable(): bool
    {
        return class_exists(PostmarkClient::class) && ! empty($this->config['server_token']);
    }

    public function pull(): iterable
    {
        $client = $this->client();
        $stream = $this->config['message_stream'] ?? 'outbound';

        // PostmarkClient::getSuppressions returns a PostmarkSuppressionList
        // whose Suppressions property is protected — read it via the
        // getSuppressions() accessor, not direct property access.
        $response = $client->getSuppressions($stream);

        foreach ($response->getSuppressions() as $entry) {
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

    public function unsuppress(string $address): bool
    {
        $client = $this->client();
        $stream = $this->config['message_stream'] ?? 'outbound';

        // PostmarkClient::deleteSuppressions expects an array of
        // [['EmailAddress' => '...']] entries first, then the stream.
        $response = $client->deleteSuppressions(
            [['EmailAddress' => strtolower($address)]],
            $stream
        );

        // The SDK throws on a real API error. Reaching here means Postmark
        // accepted the request. An entry with Status != 'Failed' confirms
        // the unsuppress is in flight. An empty Suppressions list is
        // Postmark's idempotent response when there was nothing to delete
        // (the address was already cleared); still success, just a no-op.
        foreach ($response->getSuppressions() as $entry) {
            if (isset($entry->Status) && $entry->Status === 'Failed') {
                return false;
            }
        }

        return true;
    }

    protected function client(): PostmarkClient
    {
        return new PostmarkClient($this->config['server_token']);
    }
}
