<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * An interactive setup wizard. Walks through the small set of decisions a
 * fresh install has to make — which provider, which auth scheme, which
 * optional features — writes the answers to the app's .env, publishes
 * migrations, and (optionally) finishes by running postmaster:verify so
 * the operator knows the end-to-end round trip works before any real mail
 * is sent through it.
 */
class Install extends Command
{
    protected $signature = 'postmaster:install';

    protected $description = 'Walk through Postmaster setup interactively';

    /**
     * Which webhook auth schemes each provider supports, with the default
     * first. SES is excluded — it verifies via AWS's SNS certs and needs
     * no operator-supplied credential.
     *
     * @var array<string, array<int, string>>
     */
    protected $authSchemes = [
        'sendgrid' => ['signature'],
        'mailgun'  => ['signature'],
        'resend'   => ['signature'],
        'postmark' => ['basic', 'token'],
        'ses'      => [],
    ];

    public function handle(): int
    {
        intro('Postmaster setup');

        $provider     = $this->askProvider();
        $envVars      = $this->askProviderAuth($provider);
        $persistence  = confirm('Enable the persistence layer? (records every outbound, suppression list, dashboard)', default: true);
        $storeContent = $persistence
            ? confirm('Store message subject and body for the dashboard?', default: false, hint: 'Can include PII or secrets like password-reset links — short retention by default.')
            : false;
        $sandbox      = confirm('Enable sandbox delivery in this environment?', default: false, hint: 'Intercepts every outbound email. Useful for staging.');

        if (! $persistence) {
            $envVars['POSTMASTER_PERSISTENCE'] = 'false';
        }

        if ($storeContent) {
            $envVars['POSTMASTER_STORE_CONTENT'] = 'true';
        }

        if ($sandbox) {
            $envVars['POSTMASTER_DELIVERY'] = 'sandbox';
        }

        if (empty($envVars)) {
            note('No environment variables to write. Defaults are sensible.');
        } else {
            $this->writeEnv($envVars);
        }

        if ($persistence && confirm('Publish and run the persistence migrations now?', default: true)) {
            $this->call('vendor:publish', ['--tag' => 'postmaster.migrations']);
            $this->call('migrate');
        }

        if (confirm('Run postmaster:verify to test the round trip end to end?', default: true)) {
            $this->newLine();
            $this->call('postmaster:verify');
        }

        outro('Postmaster is set up.');

        return self::SUCCESS;
    }

    /**
     * Which provider this app will send mail through. Pre-selects the one
     * Laravel's current mail config points at, when we can detect it.
     */
    protected function askProvider(): string
    {
        $detected = $this->detectProvider();

        return select(
            label: 'Which mail provider will you use?',
            options: [
                'sendgrid' => 'SendGrid',
                'postmark' => 'Postmark',
                'mailgun'  => 'Mailgun',
                'ses'      => 'Amazon SES',
                'resend'   => 'Resend',
            ],
            default: $detected,
            hint: $detected ? "Detected {$detected} from your mail config." : null,
        );
    }

    /**
     * Per-provider webhook auth setup. Returns the env vars to write.
     *
     * @return array<string, string>
     */
    protected function askProviderAuth(string $provider): array
    {
        return match ($provider) {
            'sendgrid' => $this->askSendGridAuth(),
            'mailgun'  => $this->askMailgunAuth(),
            'resend'   => $this->askResendAuth(),
            'postmark' => $this->askPostmarkAuth(),
            'ses'      => $this->askSesAuth(),
            default    => [],
        };
    }

    /**
     * @return array<string, string>
     */
    protected function askSendGridAuth(): array
    {
        note('SendGrid signs each webhook with ECDSA. Enable "Signed Event Webhook" in your SendGrid Mail Settings, then paste the Verification Key.');

        $key = password(
            label: 'SendGrid Verification Key',
            placeholder: 'MIIBC...',
            required: true,
        );

        return ['POSTMASTER_SENDGRID_VERIFICATION_KEY' => $key];
    }

    /**
     * @return array<string, string>
     */
    protected function askMailgunAuth(): array
    {
        note('Mailgun signs webhooks with HMAC-SHA256. The signing key is at Sending → Webhooks → HTTP webhook signing key.');

        $key = password(
            label: 'Mailgun HTTP webhook signing key',
            required: true,
        );

        return ['POSTMASTER_MAILGUN_SIGNING_KEY' => $key];
    }

    /**
     * @return array<string, string>
     */
    protected function askResendAuth(): array
    {
        note('Resend signs webhooks via Svix. Find the signing secret in your Resend Webhooks dashboard — it starts with "whsec_".');

        $secret = password(
            label: 'Resend webhook signing secret',
            placeholder: 'whsec_...',
            required: true,
            validate: fn ($v) => str_starts_with($v, 'whsec_') ? null : 'Resend signing secrets start with "whsec_".',
        );

        return ['POSTMASTER_RESEND_SIGNING_SECRET' => $secret];
    }

    /**
     * @return array<string, string>
     */
    protected function askPostmarkAuth(): array
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

    /**
     * @return array<string, string>
     */
    protected function askSesAuth(): array
    {
        note("SES delivers events through SNS; the package verifies each message's signature against AWS's certs automatically. No operator-supplied credential is needed.");
        note('After install, subscribe an SNS topic to your webhook URL — the SubscriptionConfirmation handshake completes itself.');

        return [];
    }

    /**
     * Best-effort detection of which provider Laravel's mail config points
     * at — used to pre-select the right option in the picker.
     */
    protected function detectProvider(): ?string
    {
        $default = config('mail.default');
        $config  = config("mail.mailers.{$default}", []);
        $transport = $config['transport'] ?? $default;

        return match ($transport) {
            'ses', 'ses-v2' => 'ses',
            'postmark'      => 'postmark',
            'mailgun'       => 'mailgun',
            'resend'        => 'resend',
            default         => null,
        };
    }

    /**
     * Update the app's .env file with the answers — preserving comments,
     * order, and existing values. Existing keys are overwritten in place;
     * new keys are appended at the end. The previous file is backed up to
     * .env.backup beforehand.
     *
     * @param array<string, string> $vars
     */
    protected function writeEnv(array $vars): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            warning("No .env file found at {$path}. The following variables need to be set manually:");
            foreach ($vars as $key => $value) {
                $this->components->twoColumnDetail($key, $this->shorten($value));
            }
            return;
        }

        info('The following will be written to .env:');

        foreach ($vars as $key => $value) {
            $this->components->twoColumnDetail($key, $this->shorten($value));
        }

        if (! confirm('Write them?', default: true)) {
            note('Nothing written.');
            return;
        }

        copy($path, $path.'.backup');

        $contents = file_get_contents($path);
        $lines    = preg_split('/\R/', $contents);

        foreach ($vars as $key => $value) {
            $written  = false;
            $rendered = $key.'='.$this->escape($value);

            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line)) {
                    $lines[$index] = $rendered;
                    $written = true;
                    break;
                }
            }

            if (! $written) {
                $lines[] = $rendered;
            }
        }

        file_put_contents($path, implode("\n", $lines));

        info(".env updated. Previous version backed up to .env.backup.");
    }

    /**
     * Quote and escape an .env value when it has whitespace or quote chars.
     */
    protected function escape(string $value): string
    {
        if ($value === '' || preg_match('/\s|"|#|\\\\/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    /**
     * Shorten a secret for display so we don't echo it whole.
     */
    protected function shorten(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        return str_repeat('•', strlen($value) - 4).substr($value, -4);
    }
}
