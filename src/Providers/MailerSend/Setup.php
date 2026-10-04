<?php

namespace STS\Postmaster\Providers\MailerSend;

use STS\Postmaster\Providers\AbstractProviderSetup;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class Setup extends AbstractProviderSetup
{
    public function name(): string
    {
        return 'mailersend';
    }

    public function label(): string
    {
        return 'MailerSend';
    }

    public function transportNames(): array
    {
        // mailersend/laravel-driver registers "mailersend".
        return ['mailersend'];
    }

    public function smtpHints(): array
    {
        return ['smtp.mailersend.net'];
    }

    public function sendingKeySet(array $mailer): ?bool
    {
        // mailersend/laravel-driver merges the mailer over its own config.
        return $this->anySet($mailer['api_key'] ?? null, config('mailersend-driver.api_key'));
    }

    public function webhookAuthConfigured(): bool
    {
        return (bool) $this->providerConfig('signing_secret');
    }

    public function askWebhookAuth(): array
    {
        note('In MailerSend: Integrations → Webhooks → your webhook. Choose version 2, then copy its signing secret.');

        return ['POSTMASTER_MAILERSEND_SIGNING_SECRET' => password(label: 'MailerSend webhook signing secret', required: true)];
    }

    public function askSuppressionSync(): array
    {
        $values = [];

        if ($this->providerConfig('api_key')) {
            info('Found a MailerSend API token already in your environment. Sync will use it.');
        } else {
            $values['POSTMASTER_MAILERSEND_API_KEY'] = password(
                label: 'MailerSend API token',
                hint: 'Integrations → API tokens. Needs full access to Suppressions.',
                required: true,
            );
        }

        $values['POSTMASTER_MAILERSEND_DOMAIN_ID'] = text(
            label: 'MailerSend domain ID',
            hint: 'Leave empty to sync every domain on the account.',
            default: (string) $this->providerConfig('domain_id'),
        );

        return array_filter($values, fn ($value) => $value !== '');
    }

    public function webhookAuthGuidance(): array
    {
        return [
            'POSTMASTER_MAILERSEND_SIGNING_SECRET '.$this->isSet($this->providerConfig('signing_secret')).'. Copy it from MailerSend → Integrations → Webhooks. Each domain\'s webhook has its own secret; separate several with commas.',
            'Choose payload version 2 when you create the webhook. Postmaster reads version 1 too, but MailerSend recommends 2.',
            'Select activity events. Pick either the plain or the unique variant of opened and clicked, not both, or each first open counts twice.',
            $this->configClearReminder(),
        ];
    }
}
