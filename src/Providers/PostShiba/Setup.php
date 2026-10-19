<?php

namespace STS\Postmaster\Providers\PostShiba;

use STS\Postmaster\Providers\AbstractProviderSetup;

use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class Setup extends AbstractProviderSetup
{
    public function name(): string
    {
        return 'postshiba';
    }

    public function label(): string
    {
        return 'PostShiba';
    }

    public function transportNames(): array
    {
        // postshiba/postshiba registers "postshiba".
        return ['postshiba'];
    }

    public function smtpHints(): array
    {
        return ['smtp.postshiba.com'];
    }

    public function sendingKeySet(array $mailer): ?bool
    {
        // The transport reads only config/services.php, never the mailer.
        return $this->anySet(config('services.postshiba.key'), config('services.postshiba.api_key'));
    }

    public function suppressionAddress(string $address): string
    {
        // PostShiba stores and checks suppressions without the "+tag".
        return preg_replace('/\+[^@]*(?=@)/', '', $address, 1);
    }

    public function webhookAuthConfigured(): bool
    {
        return (bool) $this->providerConfig('signing_secret');
    }

    public function askWebhookAuth(): array
    {
        note('In PostShiba: Webhooks → your endpoint. Copy its signing secret.');

        return ['POSTMASTER_POSTSHIBA_SIGNING_SECRET' => password(label: 'PostShiba webhook signing secret', required: true)];
    }

    public function askSuppressionSync(): array
    {
        note('Sync needs a Full access API key. The Send only key your app sends with can\'t read suppressions.');

        return [
            'POSTMASTER_POSTSHIBA_API_KEY' => password(label: 'PostShiba Full access API key', required: true),
            'POSTMASTER_POSTSHIBA_TEAM_ID' => text(
                label: 'PostShiba team ID',
                hint: 'The short ID in your dashboard URLs, such as OjMnKv.',
                default: (string) $this->providerConfig('team_id'),
                required: true,
            ),
        ];
    }

    public function webhookAuthGuidance(): array
    {
        return [
            'POSTMASTER_POSTSHIBA_SIGNING_SECRET '.$this->isSet($this->providerConfig('signing_secret')).'. Copy it from PostShiba → Webhooks → your endpoint. Each endpoint has its own secret; separate several with commas.',
            'PostShiba signs the timestamp and raw body with HMAC-SHA256. Keep the server clock accurate; signatures expire after five minutes.',
            'Test events from "Send test event" get a 2xx and are not recorded.',
            $this->configClearReminder(),
        ];
    }
}
