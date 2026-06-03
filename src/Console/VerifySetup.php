<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Listeners\RelayVerificationEvent;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * An interactive setup check: shows the webhook URL the configured provider
 * should POST to, sends a real test email, then watches for the delivery
 * webhook to come back — confirming the whole round trip end to end.
 */
class VerifySetup extends Command
{
    protected $signature = 'postmaster:verify {--timeout=120 : Seconds to wait for a webhook}';

    protected $description = 'Send a test email and confirm delivery webhooks reach your app';

    /**
     * Statuses that end the watch. A delivery confirms the whole round trip;
     * a bounce / drop / complaint is just as terminal — no delivery is coming
     * — and still proves webhooks reach the app, so there's nothing to gain by
     * spinning out the rest of the timeout.
     *
     * @var array<int, string>
     */
    protected const TERMINAL_STATUSES = [
        EmailEvent::STATUS_DELIVERED,
        EmailEvent::STATUS_BOUNCED,
        EmailEvent::STATUS_DROPPED,
        EmailEvent::STATUS_COMPLAINED,
    ];

    /**
     * Mail transports that map directly to a Postmaster provider.
     *
     * @var array<string, string>
     */
    protected $transportProviders = [
        'ses'      => 'ses',
        'ses-v2'   => 'ses',
        'postmark' => 'postmark',
        'mailgun'  => 'mailgun',
        'resend'   => 'resend',
    ];

    /**
     * Substrings that identify a provider from an SMTP host — the clue when
     * mail goes out over SMTP rather than a provider's API transport.
     *
     * @var array<string, string>
     */
    protected $smtpHints = [
        'postmarkapp.com' => 'postmark',
        'sendgrid.net'    => 'sendgrid',
        'mailgun.org'     => 'mailgun',
        'amazonaws.com'   => 'ses',
        'resend.com'      => 'resend',
    ];

    public function handle(): int
    {
        $this->components->info('Verifying your Postmaster setup.');

        if (config('postmaster.delivery') === 'sandbox') {
            $this->newLine();
            $this->components->warn(
                'Sandbox delivery is enabled (POSTMASTER_DELIVERY=sandbox), so outbound mail '
                .'is intercepted and never sent — a test email cannot produce a delivery '
                .'webhook. Set POSTMASTER_DELIVERY=normal to run the full round-trip check.'
            );

            if (! config('postmaster.persistence.enabled')) {
                $this->components->warn(
                    'Sandbox is also running without persistence (POSTMASTER_PERSISTENCE=false), '
                    .'so intercepted mail is suppressed but recorded nowhere.'
                );
            }

            return self::FAILURE;
        }

        $provider = $this->resolveProvider();

        if ($provider === null) {
            return self::FAILURE;
        }

        $url = $this->webhookUrl($provider);

        $this->newLine();
        $this->components->twoColumnDetail('Mail transport', $this->mailTransport() ?: 'unknown');
        $this->components->twoColumnDetail('Provider', $provider);
        $this->components->twoColumnDetail('Webhook URL', $url);
        $this->newLine();

        if ($this->looksLocal($url)) {
            $this->components->warn(
                'That webhook URL points at a local address your provider cannot reach. '
                .'Expose your app with a public tunnel (herd share, ngrok, Expose, ...) and '
                .'register that public URL instead — set APP_URL so it shows correctly here.'
            );
            $this->newLine();
        }

        if (! confirm("Have you set that webhook URL in your {$provider} dashboard?")) {
            $this->components->warn("Add the webhook URL above in your {$provider} dashboard, then run this command again.");

            return self::FAILURE;
        }

        $canWatch = ! $this->cacheIsPerProcess();

        if (! $canWatch) {
            $this->components->warn(
                'Your cache store ('.config('cache.default').') is per-process, so this command '
                .'cannot watch for the webhook. It will send the test email; set a shared cache '
                .'store (file, redis, database, ...) to watch live.'
            );
        }

        $address = $this->askForAddress();

        $messageId = $this->sendTestEmail($address);

        if ($messageId === false) {
            return self::FAILURE;
        }

        $this->components->info("Test email sent to {$address}.");

        if (! $canWatch || $messageId === null) {
            $this->line('  Listen for the EmailEvent in your application to confirm webhook delivery.');

            return self::SUCCESS;
        }

        return $this->waitForWebhook($messageId, max(0, (int) $this->option('timeout')));
    }

    /**
     * Whether the cache store is per-process (array/null) and so cannot carry
     * a signal from the web process back to this command.
     */
    protected function cacheIsPerProcess(): bool
    {
        $store = config('cache.default');

        return in_array(config("cache.stores.{$store}.driver"), ['array', 'null'], true);
    }

    /**
     * Decide which provider to verify — detected from the mail config where
     * possible, confirmed with (or chosen by) the user.
     */
    protected function resolveProvider(): ?string
    {
        $providers = array_keys(config('postmaster.providers', []));

        if (empty($providers)) {
            $this->components->error('No providers are configured in config/postmaster.php.');

            return null;
        }

        [$guess, $why] = $this->guessProvider();

        if ($guess !== null && in_array($guess, $providers, true)) {
            if (confirm("Detected the \"{$guess}\" provider {$why}. Verify that one?", default: true)) {
                return $guess;
            }
        } else {
            $this->components->warn('Could not determine your provider from the mail config.');
        }

        return select('Which provider are you verifying?', $providers);
    }

    /**
     * Guess the provider from the mail config: a direct transport match, or
     * the SMTP host when mail goes out over SMTP.
     *
     * @return array{0: string|null, 1: string}
     */
    protected function guessProvider(): array
    {
        $transport = $this->mailTransport();

        if ($transport === null) {
            return [null, ''];
        }

        if (isset($this->transportProviders[$transport])) {
            return [$this->transportProviders[$transport], "from the \"{$transport}\" mail transport"];
        }

        if ($transport === 'smtp') {
            $host = (string) config('mail.mailers.'.config('mail.default').'.host');

            foreach ($this->smtpHints as $needle => $provider) {
                if ($host !== '' && str_contains($host, $needle)) {
                    return [$provider, "from the SMTP host \"{$host}\""];
                }
            }
        }

        return [null, ''];
    }

    /**
     * The transport name of the default mailer, e.g. "postmark" or "smtp".
     */
    protected function mailTransport(): ?string
    {
        $mailer = config('mail.default');

        return $mailer ? config("mail.mailers.{$mailer}.transport") : null;
    }

    /**
     * The absolute webhook URL the provider should POST events to.
     */
    protected function webhookUrl(string $provider): string
    {
        try {
            return route('webhook.postmaster', ['provider' => $provider]);
        } catch (Throwable $e) {
            $base = rtrim((string) config('app.url'), '/');
            $path = trim((string) config('postmaster.url', 'webhooks/postmaster'), '/');

            return "{$base}/{$path}/{$provider}";
        }
    }

    /**
     * Whether a URL's host is a local/private address a provider's servers
     * could not POST a webhook to.
     */
    protected function looksLocal(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return true;
        }

        $host = strtolower($host);

        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return true;
        }

        foreach (['.test', '.local', '.localhost', '.example', '.invalid'] as $tld) {
            if (str_ends_with($host, $tld)) {
                return true;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }

    /**
     * Prompt for a real recipient address, re-asking until one is valid.
     */
    protected function askForAddress(): string
    {
        return text(
            label: 'Send the test email to which address?',
            placeholder: 'you@example.com',
            required: true,
            validate: fn ($v) => filter_var(trim($v), FILTER_VALIDATE_EMAIL)
                ? null
                : 'That is not a valid email address.',
            hint: 'Use a real inbox you can check.',
        );
    }

    /**
     * Send the test email. Returns the sent message id, null if it sent but
     * no id was available, or false if the send failed (already reported).
     */
    protected function sendTestEmail(string $address): string|null|false
    {
        try {
            $sent = Mail::raw($this->body(), function ($message) use ($address) {
                $message->to($address)->subject('Postmaster setup verification');
            });

            return $sent?->getMessageId();
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error('The test email failed to send.');
            $this->line('  <fg=red>'.$e->getMessage().'</>');

            return false;
        }
    }

    /**
     * Watch for webhook events on the test message until one arrives, showing
     * a live spinner and reporting each event the instant it lands.
     *
     * The webhook arrives in a separate process; RelayVerificationEvent mirrors
     * matching events into the cache, which this loop polls.
     */
    protected function waitForWebhook(string $messageId, int $timeout): int
    {
        Cache::forget(RelayVerificationEvent::EVENTS_KEY);
        Cache::forget(RelayVerificationEvent::AUTH_FAILED_KEY);
        Cache::put(RelayVerificationEvent::WATCHING_KEY, $messageId, now()->addMinutes(10));

        $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

        $start      = microtime(true);
        $lastPoll   = -2.0;
        $frame      = 0;
        $shown      = 0;
        $terminal   = null;
        $authFailed = null;

        $this->newLine();

        while (($elapsed = microtime(true) - $start) < $timeout && $terminal === null && $authFailed === null) {
            if (microtime(true) - $lastPoll >= 1.0) {
                $lastPoll = microtime(true);

                $events = Cache::get(RelayVerificationEvent::EVENTS_KEY, []);
                $events = is_array($events) ? $events : [];

                while ($shown < count($events)) {
                    $entry = $events[$shown++];

                    if (! is_array($entry)) {
                        continue;
                    }

                    $this->clearLine();
                    $this->showEvent($entry);

                    if (in_array($entry['status'] ?? null, self::TERMINAL_STATUSES, true)) {
                        $terminal = $entry['status'];
                    }
                }

                // A webhook arrived but the provider's request failed our auth
                // check — a far more actionable result than a silent timeout.
                if ($auth = Cache::get(RelayVerificationEvent::AUTH_FAILED_KEY)) {
                    $authFailed = is_array($auth) ? $auth : ['provider' => null];
                }
            }

            if ($terminal === null && $authFailed === null) {
                $this->output->write(sprintf(
                    "\r %s %s  <fg=gray>%ds</>   ",
                    $frames[$frame++ % count($frames)],
                    $shown > 0 ? 'Watching for the delivery webhook...' : 'Waiting for a webhook...',
                    (int) $elapsed
                ));

                usleep(125000);
            }
        }

        $this->clearLine();
        Cache::forget(RelayVerificationEvent::WATCHING_KEY);
        Cache::forget(RelayVerificationEvent::EVENTS_KEY);
        Cache::forget(RelayVerificationEvent::AUTH_FAILED_KEY);

        return $this->reportOutcome($terminal, $shown, $timeout, $authFailed);
    }

    /**
     * Print one received webhook event as a tidy single line: an icon, the
     * status, and a human-readable time — no INFO badge, no blank padding.
     *
     * @param array<string, mixed> $entry
     */
    protected function showEvent(array $entry): void
    {
        $status = (string) ($entry['status'] ?? 'event');
        $bad    = in_array($status, [
            EmailEvent::STATUS_BOUNCED,
            EmailEvent::STATUS_DROPPED,
            EmailEvent::STATUS_COMPLAINED,
        ], true);

        $this->line(sprintf(
            '  %s  <fg=%s;options=bold>%s</> <fg=gray>at %s</>',
            $this->iconFor($status),
            $bad ? 'yellow' : 'green',
            $status,
            $this->humanTime($entry['at'] ?? null),
        ));
    }

    /**
     * Render the final result. A delivery is the celebrated success; a bounce /
     * drop / complaint still confirms the webhook path works, so it's reported
     * as a success with a caveat about the test message itself. A webhook that
     * arrived but failed authorization is the most actionable failure of all.
     *
     * @param array<string, mixed>|null $authFailed
     */
    protected function reportOutcome(?string $terminal, int $shown, int $timeout, ?array $authFailed = null): int
    {
        $this->newLine();

        if ($authFailed !== null) {
            $provider = is_string($authFailed['provider'] ?? null) ? $authFailed['provider'] : null;

            $this->line('  🔒 <fg=red;options=bold>A webhook arrived, but it failed authorization.</>');
            $this->newLine();
            $this->line(
                '  Your app is reachable and the provider is posting events'
                .($provider ? " for [{$provider}]" : '')
                .' — the webhook is just being rejected before it can be processed.'
            );
            $this->newLine();
            $this->components->bulletList($this->authFailureGuidance($provider));

            return self::FAILURE;
        }

        if ($terminal === EmailEvent::STATUS_DELIVERED) {
            $this->line('  🎉 <fg=green;options=bold>Email delivery and webhook handling are set up and working properly!</>');
            $this->newLine();

            return self::SUCCESS;
        }

        if ($terminal !== null) {
            $this->components->warn(
                "Webhooks are reaching your app — but the test message {$terminal} instead of delivering. "
                ."Your setup looks correct; try a different inbox to confirm a clean delivery."
            );

            return self::SUCCESS;
        }

        if ($shown > 0) {
            $this->components->warn(
                "Webhooks are reaching your app, but no delivery confirmation arrived within {$timeout}s. "
                ."The delivery event may still be in flight."
            );

            return self::SUCCESS;
        }

        $this->components->warn("No webhook received within {$timeout}s.");
        $this->components->bulletList([
            'Confirm the webhook URL is set exactly as shown, on the right provider.',
            'Confirm your app is reachable at that public URL.',
            'Check the provider auth/signature settings if requests are being rejected.',
        ]);

        return self::FAILURE;
    }

    /**
     * Concrete, provider-specific guidance for an auth failure — which .env
     * variable carries the credential (and whether it's currently set), and
     * roughly where to find the matching value in the provider's dashboard.
     *
     * @return array<int, string>
     */
    protected function authFailureGuidance(?string $provider): array
    {
        $clear = 'After editing .env, run `php artisan config:clear` — cached config keeps the old values.';

        return match ($provider) {
            'postmark'  => $this->postmarkGuidance($clear),
            'sendgrid'  => [
                'SendGrid signs events with an ECDSA key. POSTMASTER_SENDGRID_VERIFICATION_KEY '
                    .$this->isSet(config('postmaster.providers.sendgrid.verification_key')).'.',
                'In SendGrid: Settings → Mail Settings → Event Webhook, turn on "Enable Signed Event Webhook", '
                    .'then copy the Verification Key into POSTMASTER_SENDGRID_VERIFICATION_KEY.',
                $clear,
            ],
            'mailgun'   => [
                'Mailgun signs webhooks with your HTTP webhook signing key. POSTMASTER_MAILGUN_SIGNING_KEY '
                    .'(falls back to MAILGUN_SECRET) '.$this->isSet(config('postmaster.providers.mailgun.signing_key')).'.',
                'In Mailgun: Send → Webhooks, copy the "HTTP webhook signing key" into POSTMASTER_MAILGUN_SIGNING_KEY '
                    .'— this is the webhook signing key, not your sending API key.',
                $clear,
            ],
            'resend'    => [
                'Resend signs webhooks (Svix) with a whsec_ secret. POSTMASTER_RESEND_SIGNING_SECRET '
                    .$this->isSet(config('postmaster.providers.resend.signing_secret')).'.',
                'In Resend: Webhooks → select your endpoint → copy the "Signing Secret" (whsec_...) '
                    .'into POSTMASTER_RESEND_SIGNING_SECRET.',
                $clear,
            ],
            'ses'       => [
                'SES delivers through SNS, which is verified against the signature embedded in each message '
                    .'— there is no secret to set in .env.',
                'A failure here usually means the request is not a genuine SNS message: confirm your SNS topic '
                    .'subscription points at this exact URL, and that no proxy or middleware alters the raw request '
                    .'body before it reaches your app.',
            ],
            default     => [
                'Confirm the webhook auth credential matches what the provider sends (token, basic-auth, or signing secret).',
                'For signature-based providers, check the signing secret and your server clock (skew breaks signatures).',
                $clear,
            ],
        };
    }

    /**
     * Postmark supports two auth modes; tailor the advice to the configured one.
     *
     * @return array<int, string>
     */
    protected function postmarkGuidance(string $clear): array
    {
        if (config('postmaster.providers.postmark.auth') === 'token') {
            $param = config('postmaster.token_parameter', 'auth');

            return [
                "Postmark is configured for token auth. POSTMASTER_AUTH_TOKEN "
                    .$this->isSet(config('postmaster.token')).'; the webhook URL must carry it as a query string '
                    ."(?{$param}=...).",
                "In Postmark: open your message stream → Webhooks tab → edit the webhook, and confirm its URL ends "
                    ."with ?{$param}=<token> matching POSTMASTER_AUTH_TOKEN.",
                $clear,
            ];
        }

        $set = config('postmaster.basic_username') && config('postmaster.basic_password')
            ? 'are set' : 'are NOT both set';

        return [
            "Postmark uses HTTP basic auth by default. POSTMASTER_AUTH_USERNAME and POSTMASTER_AUTH_PASSWORD {$set}.",
            'In Postmark: open your message stream → Webhooks tab → edit your webhook → expand the "Basic auth" '
                .'section and confirm the username and password match POSTMASTER_AUTH_USERNAME / POSTMASTER_AUTH_PASSWORD.',
            $clear,
        ];
    }

    /**
     * "is set" / "is NOT set" for a credential, so the guidance can call out a
     * missing value outright rather than just "check it matches".
     */
    protected function isSet(mixed $value): string
    {
        return $value !== null && $value !== '' ? 'is set' : 'is NOT set';
    }

    /**
     * A small emoji cue for a webhook status — falls back to a plain envelope
     * for anything unmapped.
     */
    protected function iconFor(string $status): string
    {
        return match ($status) {
            EmailEvent::STATUS_DELIVERED  => '📬',
            EmailEvent::STATUS_OPENED     => '👀',
            EmailEvent::STATUS_CLICKED    => '🔗',
            EmailEvent::STATUS_BOUNCED    => '⚠️',
            EmailEvent::STATUS_DROPPED    => '🚫',
            EmailEvent::STATUS_COMPLAINED => '🚩',
            EmailEvent::STATUS_DEFERRED   => '⏳',
            default                       => '✉️',
        };
    }

    /**
     * Format a stored ISO-8601 timestamp as a readable local time, e.g.
     * "2:23:01 PM". Falls back to the raw value if it can't be parsed.
     */
    protected function humanTime(mixed $at): string
    {
        if (! is_string($at) || $at === '') {
            return 'just now';
        }

        try {
            return Carbon::parse($at)
                ->setTimezone(config('app.timezone') ?: 'UTC')
                ->format('g:i:s A');
        } catch (Throwable $e) {
            return $at;
        }
    }

    /**
     * Erase the current spinner line.
     */
    protected function clearLine(): void
    {
        $this->output->write("\r".str_repeat(' ', 60)."\r");
    }

    protected function body(): string
    {
        return "This is a test email from the Postmaster setup verification command "
            ."(php artisan postmaster:verify).\n\n"
            ."You can safely ignore or delete it — it was sent only to confirm that "
            ."outbound mail and delivery webhooks are working.";
    }
}
