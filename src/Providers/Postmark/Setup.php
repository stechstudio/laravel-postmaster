<?php

namespace STS\Postmaster\Providers\Postmark;

use STS\Postmaster\Providers\AbstractProviderSetup;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class Setup extends AbstractProviderSetup
{
    public function name(): string
    {
        return 'postmark';
    }

    public function label(): string
    {
        return 'Postmark';
    }

    public function transportNames(): array
    {
        return ['postmark'];
    }

    public function smtpHints(): array
    {
        return ['postmarkapp.com'];
    }

    public function askWebhookAuth(): array
    {
        note("Postmark doesn't sign webhook payloads. Postmaster authenticates them with HTTP basic auth (default) or a URL token instead. Whatever you choose, configure the same on Postmark's webhook URL.");

        $scheme = select(
            label: 'Which Postmark webhook auth would you like to use?',
            options: ['basic' => 'HTTP basic auth (recommended)', 'token' => 'URL token'],
            default: 'basic',
        );

        if ($scheme === 'basic') {
            return [
                'POSTMASTER_AUTH_USERNAME' => text(label: 'Username for HTTP basic auth', required: true),
                'POSTMASTER_AUTH_PASSWORD' => password(label: 'Password for HTTP basic auth', required: true),
            ];
        }

        return [
            'POSTMASTER_POSTMARK_AUTH' => 'token',
            'POSTMASTER_AUTH_TOKEN'    => password(label: 'URL token (appended as ?auth= on the webhook URL)', required: true),
        ];
    }

    public function webhookAuthConfigured(): bool
    {
        // Postmark authenticates webhooks with the shared token or basic-auth
        // credentials, depending on the configured scheme.
        if ($this->providerConfig('auth') === 'token') {
            return (bool) config('postmaster.token');
        }

        return (bool) config('postmaster.basic_username') && (bool) config('postmaster.basic_password');
    }

    public function askSuppressionSync(): array
    {
        note('Install the SDK with: composer require wildbit/postmark-php');

        if ($this->providerConfig('server_token')) {
            info('Found a Postmark server token already in your environment — sync will use it.');

            return [];
        }

        return ['POSTMASTER_POSTMARK_SERVER_TOKEN' => password(
            label: 'Postmark server token',
            hint: 'Servers → (your server) → API tokens.',
            required: true,
        )];
    }

    public function webhookAuthGuidance(): array
    {
        if ($this->providerConfig('auth') === 'token') {
            $param = config('postmaster.token_parameter', 'auth');

            return [
                'Postmark is configured for token auth. POSTMASTER_AUTH_TOKEN '
                    .$this->isSet(config('postmaster.token')).'; the webhook URL must carry it as a query string '
                    ."(?{$param}=...).",
                'In Postmark: open your message stream → Webhooks tab → edit the webhook, and confirm its URL ends '
                    ."with ?{$param}=<token> matching POSTMASTER_AUTH_TOKEN.",
                $this->configClearReminder(),
            ];
        }

        $set = config('postmaster.basic_username') && config('postmaster.basic_password')
            ? 'are set' : 'are NOT both set';

        return [
            "Postmark uses HTTP basic auth by default. POSTMASTER_AUTH_USERNAME and POSTMASTER_AUTH_PASSWORD {$set}.",
            'In Postmark: open your message stream → Webhooks tab → edit your webhook → expand the "Basic auth" '
                .'section and confirm the username and password match POSTMASTER_AUTH_USERNAME / POSTMASTER_AUTH_PASSWORD.',
            $this->configClearReminder(),
        ];
    }
}
