<?php

namespace STS\Postmaster\Providers\Helo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use UnexpectedValueException;

/** Only the API operations Postmaster needs; no separate SDK dependency. */
class Client
{
    public function __construct(protected string $apiKey, protected ?string $channelId = null)
    {
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function send(array $message, string $mailType = 'transactional', ?string $idempotencyKey = null): array
    {
        $endpoint = match ($mailType) {
            'transactional' => '/send/transactional',
            'broadcast' => '/send/broadcast/message',
            default => throw new InvalidArgumentException('Helo mail_type must be transactional or broadcast.'),
        };
        $headers = array_filter([
            'X-Helo-Channel-Id' => $this->channelId,
            'X-Helo-Idempotency-Key' => $idempotencyKey,
        ], fn ($value) => $value !== null && $value !== '');

        $result = $this->json($this->request()->withHeaders($headers)->post($endpoint, $message));
        // A replayed idempotency key returns PascalCase keys (Status, MessageId).
        if (is_array($result)) {
            $result = array_combine(array_map(fn ($key) => lcfirst((string) $key), array_keys($result)), $result);
        }

        if (! is_array($result) || ! in_array($result['status'] ?? null, ['accepted', 'delayed'], true)
            || ! is_string($result['messageId'] ?? null) || $result['messageId'] === '') {
            throw new UnexpectedValueException('Helo did not accept the message: '.($result['errorMessage'] ?? $result['errorCode'] ?? 'invalid API response'));
        }

        if (isset($result['suppressions']) && (! is_array($result['suppressions'])
            || count(array_filter($result['suppressions'], 'is_string')) !== count($result['suppressions']))) {
            throw new UnexpectedValueException('Invalid Helo suppressed recipients response.');
        }

        return $result;
    }

    /** @return iterable<int, array{email: string, reason: string, createdAt: string}> */
    public function suppressions(string $mailType): iterable
    {
        $offset = 0;

        do {
            $page = $this->json($this->request()->get('/suppressions', [
                'channelId' => $this->channelId,
                'mailType' => $mailType,
                'limit' => 500,
                'offset' => $offset,
            ]));

            // An invalid or truncated list must never be treated as empty:
            // the sync command would otherwise clear local suppressions.
            if (! is_array($page) || ! is_int($page['totalCount'] ?? null) || $page['totalCount'] < 0
                || ! is_array($page['results'] ?? null)) {
                throw new UnexpectedValueException('Invalid Helo suppression list response.');
            }

            foreach ($page['results'] as $row) {
                if (! is_array($row) || ! is_string($row['email'] ?? null) || $row['email'] === ''
                    || ! is_string($row['reason'] ?? null) || ! is_string($row['createdAt'] ?? null)) {
                    throw new UnexpectedValueException('Invalid Helo suppression entry.');
                }
                yield $row;
            }

            $offset += count($page['results']);
            if ($page['results'] === [] && $offset < $page['totalCount']) {
                throw new UnexpectedValueException('Helo returned an incomplete suppression list.');
            }
        } while ($offset < $page['totalCount']);
    }

    public function unsuppress(string $address, string $mailType): bool
    {
        $result = $this->json($this->request()->post('/suppressions/remove', [
            'channelId' => $this->channelId,
            'mailType' => $mailType,
            'emails' => [$address],
        ]))['results'][0] ?? null;

        if (! is_array($result) || strcasecmp($result['email'] ?? '', $address) !== 0
            || ($result['success'] ?? null) !== true) {
            // Helo can reject a complaint/unsubscribe removal inside HTTP 200.
            throw new UnexpectedValueException('Helo could not remove the suppression: '.($result['message'] ?? 'invalid API response'));
        }

        return true;
    }

    /** Surface Helo's problem detail; Laravel truncates the raw error body. */
    protected function json(Response $response): mixed
    {
        if ($response->failed()) {
            throw new UnexpectedValueException(sprintf(
                'Helo API error %d%s: %s',
                $response->status(),
                is_string($response->json('code')) ? ' ('.$response->json('code').')' : '',
                $response->json('detail') ?? $response->json('title') ?? 'no error detail',
            ));
        }

        return $response->json();
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl('https://api.helohq.com')
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(30);
    }
}
