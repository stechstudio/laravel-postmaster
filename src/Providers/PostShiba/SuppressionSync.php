<?php

namespace STS\Postmaster\Providers\PostShiba;

use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use STS\Postmaster\Contracts\SuppressionSync as Contract;
use STS\Postmaster\Models\EmailAddress;
use UnexpectedValueException;

/**
 * Mirrors a PostShiba team's suppression list through its REST API. Needs a
 * Full access key: a Send only key gets 403 on everything but sending.
 */
class SuppressionSync implements Contract
{
    protected const BASE_URL = 'https://app.postshiba.com/api/v1/teams/';

    protected const PAGE_SIZE = 1000;

    /** @param array<string, mixed> $config */
    public function __construct(protected array $config)
    {
    }

    public function isAvailable(): bool
    {
        return ! empty($this->config['api_key']) && ! empty($this->config['team_id']);
    }

    public function pull(): iterable
    {
        $after = null;

        do {
            $rows = $this->client()
                ->get($this->url(), array_filter(['after' => $after, 'limit' => self::PAGE_SIZE]))
                ->throw()
                ->json();

            // A short list would clear local suppressions, so refuse anything odd.
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new UnexpectedValueException('Invalid PostShiba suppressions response.');
            }

            foreach ($rows as $row) {
                if (! is_string($row['id'] ?? null) || ! is_string($row['email'] ?? null) || $row['email'] === '') {
                    throw new UnexpectedValueException('Invalid PostShiba suppression entry.');
                }

                yield [
                    'address' => EmailAddress::normalize($row['email']),
                    'reason' => $this->reason($row['reason'] ?? null),
                    'suppressed_at' => is_string($row['created_at'] ?? null) ? new DateTimeImmutable($row['created_at']) : null,
                ];

                $after = $row['id'];
            }
        } while (count($rows) >= self::PAGE_SIZE);
    }

    public function unsuppress(string $address): bool
    {
        $deleted = $this->client()
            ->post($this->url().'/bulk_delete', ['emails' => [$address]])
            ->throw()
            ->json('deleted');

        return (int) $deleted > 0;
    }

    /**
     * The docs name "manual" and "bounce" but not the strings for spam
     * reports and unsubscribes, so match loosely.
     */
    protected function reason(mixed $reason): string
    {
        $reason = strtolower((string) $reason);

        return match (true) {
            str_contains($reason, 'bounce') => EmailAddress::REASON_BOUNCED,
            str_contains($reason, 'spam') => EmailAddress::REASON_COMPLAINED,
            str_contains($reason, 'unsub') => EmailAddress::REASON_UNSUBSCRIBED,
            default => EmailAddress::REASON_MANUAL,
        };
    }

    protected function url(): string
    {
        return self::BASE_URL.rawurlencode((string) $this->config['team_id']).'/suppressions';
    }

    protected function client(): PendingRequest
    {
        return Http::withToken($this->config['api_key'])->acceptJson()->timeout(30);
    }
}
