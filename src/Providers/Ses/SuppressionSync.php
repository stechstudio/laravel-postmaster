<?php

namespace STS\Postmaster\Providers\Ses;

use Aws\SesV2\SesV2Client;
use DateTimeImmutable;
use STS\Postmaster\Contracts\SuppressionSync as Contract;
use STS\Postmaster\Models\EmailAddress;

/**
 * Mirrors Amazon SES's account-level suppression list into the package's
 * email_addresses table.
 *
 * Authenticates via the AWS credential chain (env vars, IAM role, shared
 * config file, etc.) — the same way every other AWS SDK call in a Laravel
 * app does. The package doesn't add new AWS env vars. Soft-depends on
 * aws/aws-sdk-php.
 */
class SuppressionSync implements Contract
{
    /**
     * SES suppression reasons mapped to our normalized values.
     */
    protected const REASON_MAP = [
        'BOUNCE'    => EmailAddress::REASON_BOUNCED,
        'COMPLAINT' => EmailAddress::REASON_COMPLAINED,
    ];

    public function __construct( protected array $config )
    {
    }

    public function isAvailable(): bool
    {
        // The SES v2 client is what carries the suppression API. The older
        // SesClient still exists but doesn't expose ListSuppressedDestinations.
        return class_exists(SesV2Client::class);
    }

    public function pull(): iterable
    {
        $client = $this->client();
        $token  = null;

        do {
            $args = ['Reasons' => array_keys(static::REASON_MAP)];

            if ($token !== null) {
                $args['NextToken'] = $token;
            }

            $response = $client->listSuppressedDestinations($args);

            foreach ($response['SuppressedDestinationSummaries'] ?? [] as $entry) {
                $address = $entry['EmailAddress'] ?? null;

                if (! is_string($address) || $address === '') {
                    continue;
                }

                yield [
                    'address'       => strtolower($address),
                    'reason'        => self::REASON_MAP[$entry['Reason'] ?? ''] ?? EmailAddress::REASON_BOUNCED,
                    'suppressed_at' => isset($entry['LastUpdateTime'])
                        ? new DateTimeImmutable('@'.$entry['LastUpdateTime']->getTimestamp())
                        : null,
                ];
            }

            $token = $response['NextToken'] ?? null;
        } while ($token !== null);
    }

    public function unsuppress(string $address): bool
    {
        $client = $this->client();

        try {
            $client->deleteSuppressedDestination(['EmailAddress' => strtolower($address)]);

            return true;
        } catch (\Aws\Exception\AwsException $e) {
            // NotFoundException = wasn't on the list; treat as a no-op.
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return false;
            }

            throw $e;
        }
    }

    protected function client(): SesV2Client
    {
        return new SesV2Client([
            'version' => 'latest',
            'region'  => $this->config['region'] ?? 'us-east-1',
            // Credentials come from the standard AWS chain: env vars
            // (AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY), IAM instance
            // role, ECS task role, ~/.aws/credentials, etc. No options
            // passed here — let the SDK find them.
        ]);
    }
}
