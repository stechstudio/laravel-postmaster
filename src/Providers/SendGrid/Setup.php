<?php

namespace STS\Postmaster\Providers\SendGrid;

use STS\Postmaster\Providers\AbstractProviderSetup;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;

class Setup extends AbstractProviderSetup
{
    public function name(): string
    {
        return 'sendgrid';
    }

    public function label(): string
    {
        return 'SendGrid';
    }

    public function smtpHints(): array
    {
        // SendGrid has no first-party Laravel transport — it's reached over
        // SMTP — so detection relies on the host alone.
        return ['sendgrid.net'];
    }

    public function askWebhookAuth(): array
    {
        note('SendGrid signs each webhook with ECDSA. In Settings → Mail Settings → Event Webhook, turn on "Enable Signed Event Webhook", then paste the Verification Key.');

        return ['POSTMASTER_SENDGRID_VERIFICATION_KEY' => password(
            label: 'SendGrid Verification Key',
            placeholder: 'MIIBC...',
            required: true,
        )];
    }

    public function webhookAuthConfigured(): bool
    {
        return (bool) $this->providerConfig('verification_key');
    }

    public function askSuppressionSync(): array
    {
        note('Install the SDK with: composer require sendgrid/sendgrid');

        if ($this->providerConfig('api_key')) {
            info('Found a SendGrid API key already in your environment — sync will use it.');

            return [];
        }

        return ['POSTMASTER_SENDGRID_API_KEY' => password(
            label: 'SendGrid API key',
            hint: 'Settings → API Keys. Needs the "Suppressions" scope.',
            required: true,
        )];
    }

    public function webhookAuthGuidance(): array
    {
        return [
            'SendGrid signs events with an ECDSA key. POSTMASTER_SENDGRID_VERIFICATION_KEY '
                .$this->isSet($this->providerConfig('verification_key')).'.',
            'In SendGrid: Settings → Mail Settings → Event Webhook, turn on "Enable Signed Event Webhook", '
                .'then copy the Verification Key into POSTMASTER_SENDGRID_VERIFICATION_KEY.',
            $this->configClearReminder(),
        ];
    }
}
