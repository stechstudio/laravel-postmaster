<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\Console\Concerns\ResolvesProvider;
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
class Verify extends Command
{
    use ResolvesProvider;

    protected $signature = 'postmaster:verify
        {--timeout=120 : Seconds to wait for a webhook}
        {--provider= : The provider to verify (defaults to auto-detection)}
        {--to= : Recipient for the test email (required for a non-interactive run)}';

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

    public function handle(): int
    {
        $this->components->info('Verifying your Postmaster setup.');

        // Sandbox delivery suppresses all outbound mail — but an admin might
        // reasonably keep it on until they've confirmed everything works. So
        // rather than refusing, warn loudly and send a single real test email
        // that bypasses the sandbox (the same escape hatch the dashboard's
        // Release action uses), leaving the POSTMASTER_DELIVERY setting alone.
        $sandboxed = config('postmaster.delivery') === 'sandbox';

        if ($sandboxed) {
            $this->newLine();
            $this->components->warn(
                'Sandbox delivery is enabled (POSTMASTER_DELIVERY=sandbox). Your app intercepts '
                .'and suppresses all outbound mail, so nothing reaches real recipients while it '
                .'stays on. For this check only, Postmaster will send ONE real test email — '
                .'bypassing the sandbox — to confirm the delivery-webhook round trip. Your '
                .'POSTMASTER_DELIVERY setting is not changed.'
            );
            $this->newLine();
        }

        $interactive = $this->canPrompt();

        $provider = $this->resolveProvider($interactive);

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

        // Interactively we confirm the webhook is registered before spending a
        // send; non-interactively we can't ask, so we assume it's set and run.
        if ($interactive && ! confirm("Have you set that webhook URL in your {$provider} dashboard?")) {
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

        $address = $this->resolveAddress($interactive);

        if ($address === null) {
            return self::FAILURE;
        }

        $messageId = $this->sendTestEmail($address, bypassSandbox: $sandboxed);

        if ($messageId === false) {
            return self::FAILURE;
        }

        $this->components->info("Test email sent to {$address}.");

        if (! $canWatch || $messageId === null) {
            $this->line('  Listen for the EmailEvent in your application to confirm webhook delivery.');

            return self::SUCCESS;
        }

        return $this->waitForWebhook($messageId, max(0, (int) $this->option('timeout')), $interactive);
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
     * Decide which provider to verify — from --provider, detected from the mail
     * config, or (interactively) confirmed with / chosen by the operator.
     */
    protected function resolveProvider(bool $interactive): ?string
    {
        if (! $interactive || $this->option('provider')) {
            return $this->resolveProviderNonInteractively($this->option('provider'));
        }

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
     * The recipient for the test email — from --to, or (interactively) prompted.
     * Returns null (after reporting) when a non-interactive run has no --to, or
     * when the supplied address is invalid.
     */
    protected function resolveAddress(bool $interactive): ?string
    {
        if ($given = $this->option('to')) {
            $given = trim($given);

            if (! filter_var($given, FILTER_VALIDATE_EMAIL)) {
                $this->components->error("\"{$given}\" is not a valid email address.");

                return null;
            }

            return $given;
        }

        if (! $interactive) {
            // Fall back to the app's own from-address — a real, owned inbox —
            // so the check can run with no arguments. A bounce there still
            // proves the webhook path works.
            $from = trim((string) config('mail.from.address'));

            if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $this->components->info("No --to given; sending the test email to your from-address ({$from}).");

                return $from;
            }

            $this->components->error('Pass --to=you@example.com to choose where the test email is sent (or set MAIL_FROM_ADDRESS).');

            return null;
        }

        return text(
            label: 'Send the test email to which address?',
            default: (string) config('mail.from.address', ''),
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
     *
     * When $bypassSandbox is true the send runs with sandbox delivery briefly
     * switched off, so the one test email reaches the transport even while
     * POSTMASTER_DELIVERY=sandbox.
     */
    protected function sendTestEmail(string $address, bool $bypassSandbox = false): string|null|false
    {
        $send = fn () => Mail::raw($this->body(), function ($message) use ($address) {
            $message->to($address)->subject('Postmaster setup verification');
        });

        try {
            $sent = $bypassSandbox ? $this->withoutSandbox($send) : $send();

            return $sent?->getMessageId();
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error('The test email failed to send.');
            $this->line('  <fg=red>'.$e->getMessage().'</>');

            return false;
        }
    }

    /**
     * Run a callback with sandbox delivery temporarily switched off, so a
     * single send reaches the transport even while POSTMASTER_DELIVERY=sandbox.
     * The InterceptSandboxMail listener reads this config at send time, so
     * flipping it here is enough; the original value is always restored.
     *
     * @template T
     * @param  \Closure(): T $callback
     * @return T
     */
    protected function withoutSandbox(\Closure $callback)
    {
        $original = config('postmaster.delivery');
        config(['postmaster.delivery' => 'normal']);

        try {
            return $callback();
        } finally {
            config(['postmaster.delivery' => $original]);
        }
    }

    /**
     * Watch for webhook events on the test message until one arrives, showing
     * a live spinner and reporting each event the instant it lands.
     *
     * The webhook arrives in a separate process; RelayVerificationEvent mirrors
     * matching events into the cache, which this loop polls.
     */
    protected function waitForWebhook(string $messageId, int $timeout, bool $interactive = true): int
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

        // No live spinner without a terminal (logs, CI) — just note the wait
        // once; events still print as they land.
        if (! $interactive) {
            $this->line("  Waiting up to {$timeout}s for a webhook...");
        }

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
                if ($interactive) {
                    $this->output->write(sprintf(
                        "\r %s %s  <fg=gray>%ds</>   ",
                        $frames[$frame++ % count($frames)],
                        $shown > 0 ? 'Watching for the delivery webhook...' : 'Waiting for a webhook...',
                        (int) $elapsed
                    ));
                }

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
            $this->components->bulletList(
                $provider !== null
                    ? $this->setupFor($provider)->webhookAuthGuidance()
                    : $this->genericWebhookAuthGuidance()
            );

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
     * Fallback guidance when the failing provider can't be identified — the
     * per-provider specifics live on each ProviderSetup::webhookAuthGuidance().
     *
     * @return array<int, string>
     */
    protected function genericWebhookAuthGuidance(): array
    {
        return [
            'Confirm the webhook auth credential matches what the provider sends (token, basic-auth, or signing secret).',
            'For signature-based providers, check the signing secret and your server clock (skew breaks signatures).',
            'After editing .env, run `php artisan config:clear` — cached config keeps the old values.',
        ];
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
