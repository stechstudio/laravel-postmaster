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
     * EmailAddress::REASON_* value we record locally. The SDK's base URL
     * already includes `/v3`, so endpoints here are recorded relative to
     * that prefix (don't prepend `/v3/` — that produces `/v3/v3/...` and 404s).
     */
    protected const LISTS = [
        'suppression/bounces'        => EmailAddress::REASON_BOUNCED,
        'suppression/blocks'         => EmailAddress::REASON_BOUNCED,
        'suppression/invalid_emails' => EmailAddress::REASON_DROPPED,
        'suppression/spam_reports'   => EmailAddress::REASON_COMPLAINED,
    ];

    public function __construct( protected array $config )
    {
    }

    public function isAvailable(): bool
    {
        return class_exists(SendGrid::class) && ! empty($this->config['api_key']);
    }

    public function pull(): iterable
    {
        foreach (static::LISTS as $endpoint => $reason) {
            yield from $this->pullList($endpoint, $reason);
        }
    }

    public function unsuppress(string $address): bool
    {
        $client    = $this->client();
        $address   = strtolower($address);
        $removed   = false;

        foreach (array_keys(static::LISTS) as $endpoint) {
            // DELETE /v3/suppression/{list}/{email} — 204 on success,
            // 404 when the address isn't on that list. The endpoint is
            // recorded relative to the SDK's /v3 base (no leading slash).
            $response = $client->client->_($endpoint)->_($address)->delete();

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
    protected function pullList(string $endpoint, string $reason): iterable
    {
        $client = $this->client();
        // SendGrid's suppression endpoints have no documented pagination
        // limit and return the full list as JSON. A million-row response
        // would be a problem, but at typical volumes this is fine.
        $response = $client->client->_($endpoint)->get();

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
