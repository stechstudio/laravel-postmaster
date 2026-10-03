<?php

namespace STS\Postmaster\Providers\Helo;

use STS\Postmaster\Providers\AbstractProviderSetup;

use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class Setup extends AbstractProviderSetup
{
    public function name(): string
    {
        return 'helo';
    }

    public function label(): string
    {
        return 'Helo';
    }

    public function transportNames(): array
    {
        return ['helo'];
    }

    public function smtpHints(): array
    {
        return ['smtp.helohq.com'];
    }

    public function webhookAuthConfigured(): bool
    {
        return (bool) $this->providerConfig('signing_key');
    }

    public function askWebhookAuth(): array
    {
        note('Copy the signing key for this endpoint from Helo → Webhooks. This is separate from your API key.');

        return ['POSTMASTER_HELO_SIGNING_KEY' => password(label: 'Helo webhook signing key', required: true)];
    }

    public function askSuppressionSync(): array
    {
        note('Helo sync uses Laravel HTTP; no SDK is needed. Select the channel and mail type this app uses. Postmaster stores suppressions globally.');

        $values = [];
        if (! $this->providerConfig('api_key')) {
            $values['POSTMASTER_HELO_API_KEY'] = password(label: 'Helo API key', required: true);
        }
        if (! $this->providerConfig('channel_id')) {
            $values['POSTMASTER_HELO_CHANNEL_ID'] = text(label: 'Helo channel ID', required: true);
        }
        $values['POSTMASTER_HELO_MAIL_TYPE'] = select(
            label: 'Helo mail type',
            options: ['transactional' => 'Transactional', 'broadcast' => 'Broadcast'],
            default: $this->providerConfig('mail_type', 'transactional'),
        );

        return $values;
    }

    public function webhookAuthGuidance(): array
    {
        return [
            'POSTMASTER_HELO_SIGNING_KEY '.$this->isSet($this->providerConfig('signing_key')).'. Copy the signing key from Helo → Webhooks.',
            'Select message and recipient events for this channel. Domain verification events are not email events.',
            'Helo signs the raw body with HMAC-SHA256. Keep the server clock accurate; signatures expire after five minutes.',
            $this->configClearReminder(),
        ];
    }
}
