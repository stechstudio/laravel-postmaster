<?php

namespace STS\Postmaster\Providers\Mailgun;

use STS\Postmaster\Providers\AbstractProviderSetup;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class Setup extends AbstractProviderSetup
{
    public function name(): string
    {
        return 'mailgun';
    }

    public function label(): string
    {
        return 'Mailgun';
    }

    public function transportNames(): array
    {
        return ['mailgun'];
    }

    public function smtpHints(): array
    {
        return ['mailgun.org'];
    }

    public function askWebhookAuth(): array
    {
        note('Mailgun signs webhooks with HMAC-SHA256. The signing key is at Send → Webhooks → HTTP webhook signing key.');

        return ['POSTMASTER_MAILGUN_SIGNING_KEY' => password(
            label: 'Mailgun HTTP webhook signing key',
            required: true,
        )];
    }

    public function webhookAuthConfigured(): bool
    {
        return (bool) $this->providerConfig('signing_key');
    }

    public function askSuppressionSync(): array
    {
        note('Install the SDK with: composer require mailgun/mailgun-php');

        $vars = [];

        if ($this->providerConfig('api_key')) {
            info('Found a Mailgun API key already in your environment — sync will use it.');
        } else {
            $vars['POSTMASTER_MAILGUN_API_KEY'] = password(
                label: 'Mailgun API key',
                hint: 'Settings → API Keys → Private API key.',
                required: true,
            );
        }

        if ($this->providerConfig('domain')) {
            info('Found a Mailgun sending domain already in your environment — sync will use it.');
        } else {
            $vars['POSTMASTER_MAILGUN_DOMAIN'] = text(
                label: 'Mailgun sending domain',
                placeholder: 'mg.example.com',
                required: true,
            );
        }

        return $vars;
    }

    public function authFailureGuidance(): array
    {
        return [
            'Mailgun signs webhooks with your HTTP webhook signing key. POSTMASTER_MAILGUN_SIGNING_KEY '
                .'(falls back to MAILGUN_SECRET) '.$this->isSet($this->providerConfig('signing_key')).'.',
            'In Mailgun: Send → Webhooks, copy the "HTTP webhook signing key" into POSTMASTER_MAILGUN_SIGNING_KEY '
                .'— this is the webhook signing key, not your sending API key.',
            $this->configClearReminder(),
        ];
    }
}
