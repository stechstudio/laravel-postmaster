<?php

namespace STS\Postmaster\Providers\MailerSend;

use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use STS\Postmaster\Contracts\SuppressionSync as Contract;
use STS\Postmaster\Models\EmailAddress;
use UnexpectedValueException;

/**
 * Mirrors MailerSend's suppression lists through its REST API. Needs an API
 * token with the suppressions_full scope; no SDK.
 *
 * The on-hold list is left out on purpose: MailerSend releases those
 * recipients on its own after a few days, so they aren't suppressed.
 */
class SuppressionSync implements Contract
{
    protected const BASE_URL = 'https://api.mailersend.com/v1/suppressions/';

    protected const PAGE_SIZE = 100;

    protected const LISTS = [
        'hard-bounces' => EmailAddress::REASON_BOUNCED,
        'spam-complaints' => EmailAddress::REASON_COMPLAINED,
        'unsubscribes' => EmailAddress::REASON_UNSUBSCRIBED,
        'blocklist' => EmailAddress::REASON_MANUAL,
    ];

    /** @param array<string, mixed> $config */
    public function __construct(protected array $config)
    {
    }

    public function isAvailable(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function pull(): iterable
    {
        foreach (self::LISTS as $list => $reason) {
            foreach ($this->entries($list) as $entry) {
                yield [
                    'address' => EmailAddress::normalize($entry['address']),
                    'reason' => $reason,
                    'suppressed_at' => is_string($entry['created_at'] ?? null) ? new DateTimeImmutable($entry['created_at']) : null,
                ];
            }
        }
    }

    public function unsuppress(string $address): bool
    {
        $removed = false;

        foreach (array_keys(self::LISTS) as $list) {
            $ids = [];
            foreach ($this->entries($list) as $entry) {
                if (strcasecmp($entry['address'], $address) === 0) {
                    $ids[] = $entry['id'];
                }
            }

            if ($ids !== []) {
                $this->client()->delete(self::BASE_URL.$list, array_filter(['ids' => $ids, 'domain_id' => $this->domainId()]))->throw();
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * Every addressable entry on one list. Throws on anything unexpected,
     * because a short list would clear local suppressions.
     *
     * @return \Generator<int, array{id: string, address: string, created_at: mixed}>
     */
    protected function entries(string $list): \Generator
    {
        for ($page = 1; ; $page++) {
            $body = $this->client()
                ->get(self::BASE_URL.$list, array_filter(['domain_id' => $this->domainId(), 'limit' => self::PAGE_SIZE, 'page' => $page]))
                ->throw()
                ->json();

            if (! is_array($body) || ! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
                throw new UnexpectedValueException("Invalid MailerSend $list response.");
            }

            foreach ($body['data'] as $row) {
                // A blocklist pattern like .*@example.com isn't one address.
                if ($list === 'blocklist' && ($row['type'] ?? null) === 'pattern') {
                    continue;
                }

                $address = $list === 'blocklist' ? ($row['pattern'] ?? null) : ($row['recipient']['email'] ?? null);

                if (! is_string($row['id'] ?? null) || ! is_string($address) || $address === '') {
                    throw new UnexpectedValueException("Invalid MailerSend $list entry.");
                }

                yield ['id' => $row['id'], 'address' => $address, 'created_at' => $row['created_at'] ?? null];
            }

            // MailerSend's SDKs page by links.next. Without links, only a
            // short page proves the list has ended.
            $more = is_array($body['links'] ?? null)
                ? ! empty($body['links']['next'])
                : count($body['data']) >= self::PAGE_SIZE;

            if (! $more || $body['data'] === []) {
                return;
            }
        }
    }

    protected function client(): PendingRequest
    {
        return Http::withToken($this->config['api_key'])->acceptJson()->timeout(30);
    }

    protected function domainId(): ?string
    {
        return ($this->config['domain_id'] ?? null) ?: null;
    }
}
