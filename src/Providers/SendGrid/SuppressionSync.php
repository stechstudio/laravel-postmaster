<?php

namespace STS\Postmaster\Providers\SendGrid;

use DateTimeImmutable;
use SendGrid;
use STS\Postmaster\Contracts\SuppressionSync as Contract;
use STS\Postmaster\Models\EmailAddress;

/**
 * Mirrors SendGrid's suppression lists into the package's email_addresses
 * table — bounces, blocks, spam reports, and invalid emails are all
 * suppressions from our perspective.
 *
 * Soft-depends on sendgrid/sendgrid; isAvailable() reports whether the SDK
 * is installed AND an API key is configured.
 */
class SuppressionSync implements Contract
{
    /**
     * SendGrid suppression endpoints to pull from, mapped to the normalized
     * EmailAddress::REASON_* value we record locally.
     */
    protected const LISTS = [
        '/v3/suppression/bounces'        => EmailAddress::REASON_BOUNCED,
        '/v3/suppression/blocks'         => EmailAddress::REASON_BOUNCED,
        '/v3/suppression/invalid_emails' => EmailAddress::REASON_DROPPED,
        '/v3/suppression/spam_reports'   => EmailAddress::REASON_COMPLAINED,
    ];

    public function __construct( protected array $config )
    {
    }

    public function isAvailable()
    {
        return class_exists(SendGrid::class) && ! empty($this->config['api_key']);
    }

    public function pull()
    {
        foreach (static::LISTS as $endpoint => $reason) {
            yield from $this->pullList($endpoint, $reason);
        }
    }

    public function unsuppress( $address )
    {
        $client    = $this->client();
        $address   = strtolower($address);
        $removed   = false;

        foreach (array_keys(static::LISTS) as $endpoint) {
            // DELETE /v3/suppression/{list}/{email} — 204 on success,
            // 404 when the address isn't on that list.
            $response = $client->client->_(ltrim($endpoint, '/'))->_($address)->delete();

            if ($response->statusCode() === 204) {
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * @param string $endpoint
     * @param string $reason
     *
     * @return iterable<int, array{address: string, reason: string, suppressed_at: DateTimeImmutable|null}>
     */
    protected function pullList( string $endpoint, string $reason )
    {
        $client = $this->client();
        // SendGrid's suppression endpoints have no documented pagination
        // limit and return the full list as JSON. A million-row response
        // would be a problem, but at typical volumes this is fine.
        $response = $client->client->_(ltrim($endpoint, '/'))->get();

        if ($response->statusCode() !== 200) {
            return;
        }

        $body = json_decode((string) $response->body(), true);

        if (! is_array($body)) {
            return;
        }

        foreach ($body as $entry) {
            $address = $entry['email'] ?? null;

            if (! is_string($address) || $address === '') {
                continue;
            }

            yield [
                'address'       => strtolower($address),
                'reason'        => $reason,
                'suppressed_at' => isset($entry['created']) ? new DateTimeImmutable('@'.(int) $entry['created']) : null,
            ];
        }
    }

    protected function client(): SendGrid
    {
        return new SendGrid($this->config['api_key']);
    }
}
