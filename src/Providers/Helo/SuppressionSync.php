<?php

namespace STS\Postmaster\Providers\Helo;

use DateTimeImmutable;
use STS\Postmaster\Contracts\SuppressionSync as Contract;
use STS\Postmaster\Models\EmailAddress;
use UnexpectedValueException;

class SuppressionSync implements Contract
{
    /** @param array<string, mixed> $config */
    public function __construct(protected array $config)
    {
    }

    public function isAvailable(): bool
    {
        return ! empty($this->config['api_key']) && ! empty($this->config['channel_id'])
            && in_array($this->config['mail_type'] ?? 'transactional', ['transactional', 'broadcast'], true);
    }

    public function pull(): iterable
    {
        $client = new Client($this->config['api_key'], $this->config['channel_id']);

        foreach ($client->suppressions($this->config['mail_type'] ?? 'transactional') as $entry) {
            yield [
                'address' => EmailAddress::normalize($entry['email']),
                'reason' => match ($entry['reason']) {
                    'bounce' => EmailAddress::REASON_BOUNCED,
                    'complaint' => EmailAddress::REASON_COMPLAINED,
                    'unsubscribe' => EmailAddress::REASON_UNSUBSCRIBED,
                    'manual' => EmailAddress::REASON_MANUAL,
                    default => throw new UnexpectedValueException('Unknown Helo suppression reason: '.$entry['reason']),
                },
                'suppressed_at' => new DateTimeImmutable($entry['createdAt']),
            ];
        }
    }

    public function unsuppress(string $address): bool
    {
        return (new Client($this->config['api_key'], $this->config['channel_id']))
            ->unsuppress($address, $this->config['mail_type'] ?? 'transactional');
    }
}
