<?php

namespace STS\Postmaster\Providers\Helo;

use DateTimeImmutable;
use STS\HeloEmail\HeloClient;
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
        return class_exists(HeloClient::class)
            && ! empty(config('helo.key')) && ! empty(config('helo.channel_id'))
            && in_array($this->mailType(), ['transactional', 'broadcast'], true);
    }

    public function pull(): iterable
    {
        // Page throws on an empty page before the reported total, so a short
        // list can never clear local suppressions.
        $rows = app(HeloClient::class)->suppressions()->list(mailType: $this->mailType(), limit: 500)->lazy();

        foreach ($rows as $row) {
            [$email, $reason, $createdAt] = [$row->get('email'), $row->get('reason'), $row->get('createdAt')];

            if (! is_string($email) || $email === '' || ! is_string($reason) || ! is_string($createdAt)) {
                throw new UnexpectedValueException('Invalid Helo suppression entry.');
            }

            yield [
                'address' => EmailAddress::normalize($email),
                'reason' => match ($reason) {
                    'bounce' => EmailAddress::REASON_BOUNCED,
                    'complaint' => EmailAddress::REASON_COMPLAINED,
                    'unsubscribe' => EmailAddress::REASON_UNSUBSCRIBED,
                    'manual' => EmailAddress::REASON_MANUAL,
                    default => throw new UnexpectedValueException('Unknown Helo suppression reason: '.$reason),
                },
                'suppressed_at' => new DateTimeImmutable($createdAt),
            ];
        }
    }

    public function unsuppress(string $address): bool
    {
        $result = app(HeloClient::class)->suppressions()->remove([$address], $this->mailType())->results[0] ?? null;

        // Helo can reject an unsubscribe or complaint removal inside HTTP 200.
        if (! is_array($result) || strcasecmp($result['email'] ?? '', $address) !== 0 || ($result['success'] ?? null) !== true) {
            throw new UnexpectedValueException('Helo could not remove the suppression: '.($result['message'] ?? 'invalid API response'));
        }

        return true;
    }

    protected function mailType(): string
    {
        return $this->config['mail_type'] ?? config('helo.mail_type', 'transactional');
    }
}
