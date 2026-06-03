<?php

namespace STS\Postmaster\Providers\Ses;

use STS\Postmaster\Providers\AbstractProviderSetup;

use function Laravel\Prompts\note;

class Setup extends AbstractProviderSetup
{
    public function name(): string
    {
        return 'ses';
    }

    public function label(): string
    {
        return 'Amazon SES';
    }

    public function transportNames(): array
    {
        return ['ses', 'ses-v2'];
    }

    public function smtpHints(): array
    {
        return ['amazonaws.com'];
    }

    public function webhookVerb(): string
    {
        // SES events arrive via an SNS topic subscription, not a webhook set
        // directly in a provider dashboard.
        return 'Subscribe an SNS topic to this URL';
    }

    public function askWebhookAuth(): array
    {
        note("SES delivers events through SNS; the package verifies each message's signature against AWS's certs automatically. No operator-supplied credential is needed.");
        note('After install, subscribe an SNS topic to your webhook URL — the SubscriptionConfirmation handshake completes itself.');

        return [];
    }

    public function askSuppressionSync(): array
    {
        note('SES sync uses the AWS SDK that is (or will be) in your app — there are no Postmaster env vars to set here.');
        note('  · Install the SDK with: composer require aws/aws-sdk-php');
        note('  · The IAM identity the SDK resolves to needs ses:ListSuppressedDestinations and ses:PutSuppressedDestination.');

        return [];
    }

    public function authFailureGuidance(): array
    {
        return [
            'SES delivers through SNS, which is verified against the signature embedded in each message '
                .'— there is no secret to set in .env.',
            'A failure here usually means the request is not a genuine SNS message: confirm your SNS topic '
                .'subscription points at this exact URL, and that no proxy or middleware alters the raw request '
                .'body before it reaches your app.',
        ];
    }
}
