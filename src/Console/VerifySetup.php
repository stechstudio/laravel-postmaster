<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use STS\Postmaster\Listeners\RelayVerificationEvent;
use Throwable;

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

        if (! $this->confirm("Have you set that webhook URL in your {$provider} dashboard?")) {
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
     *
     * @return bool
     */
    protected function cacheIsPerProcess()
    {
        $store = config('cache.default');

        return in_array(config("cache.stores.{$store}.driver"), ['array', 'null'], true);
    }

    /**
     * Decide which provider to verify — detected from the mail config where
     * possible, confirmed with (or chosen by) the user.
     *
     * @return string|null
     */
    protected function resolveProvider()
    {
        $providers = array_keys(config('postmaster.providers', []));

        if (empty($providers)) {
            $this->components->error('No providers are configured in config/postmaster.php.');

            return null;
        }

        [$guess, $why] = $this->guessProvider();

        if ($guess !== null && in_array($guess, $providers, true)) {
            if ($this->confirm("Detected the \"{$guess}\" provider {$why}. Verify that one?", true)) {
                return $guess;
            }
        } else {
            $this->components->warn('Could not determine your provider from the mail config.');
        }

        return $this->choice('Which provider are you verifying?', $providers);
    }

    /**
     * Guess the provider from the mail config: a direct transport match, or
     * the SMTP host when mail goes out over SMTP.
     *
     * @return array{0: string|null, 1: string}
     */
    protected function guessProvider()
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
     *
     * @return string|null
     */
    protected function mailTransport()
    {
        $mailer = config('mail.default');

        return $mailer ? config("mail.mailers.{$mailer}.transport") : null;
    }

    /**
     * The absolute webhook URL the provider should POST events to.
     *
     * @param string $provider
     *
     * @return string
     */
    protected function webhookUrl( $provider )
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
     *
     * @param string $url
     *
     * @return bool
     */
    protected function looksLocal( $url )
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
     *
     * @return string
     */
    protected function askForAddress()
    {
        while (true) {
            $address = trim((string) $this->ask('Send the test email to which address? (use a real inbox you can check)'));

            if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                return $address;
            }

            $this->components->error('That is not a valid email address.');
        }
    }

    /**
     * Send the test email. Returns the sent message id, null if it sent but
     * no id was available, or false if the send failed (already reported).
     *
     * @param string $address
     *
     * @return string|null|false
     */
    protected function sendTestEmail( $address )
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
     *
     * @param string $messageId
     * @param int    $timeout
     *
     * @return int
     */
    protected function waitForWebhook( $messageId, $timeout )
    {
        Cache::forget(RelayVerificationEvent::EVENTS_KEY);
        Cache::put(RelayVerificationEvent::WATCHING_KEY, $messageId, now()->addMinutes(10));

        $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

        $start = microtime(true);
        $deadline = $timeout;
        $lastPoll = -2.0;
        $frame = 0;
        $shown = 0;
        $extended = false;

        $this->newLine();

        while (($elapsed = microtime(true) - $start) < $deadline) {
            if (microtime(true) - $lastPoll >= 1.0) {
                $lastPoll = microtime(true);

                $events = Cache::get(RelayVerificationEvent::EVENTS_KEY, []);
                $events = is_array($events) ? $events : [];

                while ($shown < count($events)) {
                    $entry = $events[$shown++];

                    if (is_array($entry)) {
                        $this->clearLine();
                        $this->components->info(sprintf(
                            'Event received: %s (%s)',
                            $entry['status'] ?? 'event',
                            $entry['at'] ?? ''
                        ));
                    }
                }

                if ($shown > 0 && ! $extended) {
                    $extended = true;
                    // Keep watching briefly for follow-up events (a delivery
                    // is often followed by opens and clicks).
                    $deadline = min($timeout, $elapsed + 20);
                }
            }

            $this->output->write(sprintf(
                "\r %s %s  %ds elapsed   ",
                $frames[$frame++ % count($frames)],
                $shown > 0 ? 'Watching for further events...' : 'Waiting for a webhook...',
                (int) $elapsed
            ));

            usleep(125000);
        }

        $this->clearLine();
        Cache::forget(RelayVerificationEvent::WATCHING_KEY);
        Cache::forget(RelayVerificationEvent::EVENTS_KEY);
        $this->newLine();

        if ($shown > 0) {
            $this->components->info('Verified — delivery webhooks are reaching your app.');

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
     * Erase the current spinner line.
     *
     * @return void
     */
    protected function clearLine()
    {
        $this->output->write("\r".str_repeat(' ', 60)."\r");
    }

    /**
     * @return string
     */
    protected function body()
    {
        return "This is a test email from the Postmaster setup verification command "
            ."(php artisan postmaster:verify).\n\n"
            ."You can safely ignore or delete it — it was sent only to confirm that "
            ."outbound mail and delivery webhooks are working.";
    }
}
