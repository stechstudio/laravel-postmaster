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
        note($this->packageNote());
        note('Copy the signing key for this endpoint from Helo → Webhooks. This is separate from your API key.');

        return ['POSTMASTER_HELO_SIGNING_KEY' => password(label: 'Helo webhook signing key', required: true)];
    }

    public function askSuppressionSync(): array
    {
        note('Select the channel and mail type this app uses. Postmaster stores suppressions globally.');

        $values = [];
        if (! config('helo.key')) {
            $values['HELO_API_KEY'] = password(label: 'Helo API key', required: true);
        }
        if (! config('helo.channel_id')) {
            $values['HELO_CHANNEL_ID'] = text(label: 'Helo channel ID', required: true);
        }
        $values['HELO_MAIL_TYPE'] = select(
            label: 'Helo mail type',
            options: ['transactional' => 'Transactional', 'broadcast' => 'Broadcast'],
            default: config('helo.mail_type', 'transactional'),
        );

        return $values;
    }

    public function webhookAuthGuidance(): array
    {
        return [
            'POSTMASTER_HELO_SIGNING_KEY '.$this->isSet($this->providerConfig('signing_key')).'. Copy the signing key from Helo → Webhooks. Each channel\'s webhook has its own key; separate several with commas.',
            'Select message and recipient events for this channel. Domain verification events are not email events.',
            'Helo signs the raw body with HMAC-SHA256. Keep the server clock accurate; signatures expire after five minutes.',
            $this->packageNote(),
            $this->configClearReminder(),
        ];
    }

    protected function packageNote(): string
    {
        return 'To send through Helo\'s API (MAIL_MAILER=helo) or sync suppressions, install stechstudio/laravel-helo-email. Sending through Helo\'s SMTP doesn\'t need it.';
    }
}
