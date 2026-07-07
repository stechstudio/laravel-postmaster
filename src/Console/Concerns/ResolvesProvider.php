<?php

namespace STS\Postmaster\Console\Concerns;

use STS\Postmaster\Contracts\ProviderSetup;
use STS\Postmaster\Postmaster;
use Throwable;

/**
 * Shared provider plumbing for the interactive console commands: resolve each
 * configured provider's setup profile, detect the provider from the mail
 * config, and build its webhook URL. Keeps install and verify in lockstep
 * rather than each carrying its own copy.
 */
trait ResolvesProvider
{
    /** @var array<string, ProviderSetup>|null */
    private ?array $resolvedSetups = null;

    /**
     * The setup profile for every configured provider, keyed by name.
     *
     * @return array<string, ProviderSetup>
     */
    protected function providerSetups(): array
    {
        if ($this->resolvedSetups !== null) {
            return $this->resolvedSetups;
        }

        $postmaster = app(Postmaster::class);
        $setups     = [];

        foreach (array_keys(config('postmaster.providers', [])) as $name) {
            $setups[$name] = $postmaster->setup($name);
        }

        return $this->resolvedSetups = $setups;
    }

    protected function setupFor(string $provider): ProviderSetup
    {
        return $this->providerSetups()[$provider] ?? app(Postmaster::class)->setup($provider);
    }

    /** The transport name of the default mailer, e.g. "postmark" or "smtp". */
    protected function mailTransport(): ?string
    {
        $mailer = config('mail.default');

        return $mailer ? config("mail.mailers.{$mailer}.transport") : null;
    }

    /**
     * Guess the provider from the mail config: a direct transport match, or the
     * SMTP host when mail goes out over SMTP.
     *
     * @return array{0: string|null, 1: string}
     */
    protected function guessProvider(): array
    {
        $transport = $this->mailTransport();

        if ($transport === null) {
            return [null, ''];
        }

        foreach ($this->providerSetups() as $name => $setup) {
            if (in_array($transport, $setup->transportNames(), true)) {
                return [$name, "from the \"{$transport}\" mail transport"];
            }
        }

        if ($transport === 'smtp') {
            $host = (string) config('mail.mailers.'.config('mail.default').'.host');

            foreach ($this->providerSetups() as $name => $setup) {
                foreach ($setup->smtpHints() as $needle) {
                    if ($host !== '' && str_contains($host, $needle)) {
                        return [$name, "from the SMTP host \"{$host}\""];
                    }
                }
            }
        }

        return [null, ''];
    }

    /** Just the detected provider name, or null when it can't be determined. */
    protected function detectProvider(): ?string
    {
        return $this->guessProvider()[0];
    }

    /**
     * The absolute webhook URL the provider should POST events to — the named
     * route when it's registered, otherwise built from APP_URL and the
     * configured webhook path.
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
     * Resolve the provider for a non-interactive run: an explicit --provider
     * option (validated against the configured providers) or detection from the
     * mail config. Reports the reason and returns null when neither yields a
     * configured provider, so the caller can exit with a failure.
     */
    protected function resolveProviderNonInteractively(?string $option): ?string
    {
        $configured = array_keys(config('postmaster.providers', []));

        if (empty($configured)) {
            $this->components->error('No providers are configured in config/postmaster.php.');

            return null;
        }

        if ($option !== null && $option !== '') {
            if (! in_array($option, $configured, true)) {
                $this->components->error("Unknown provider \"{$option}\". Configured: ".implode(', ', $configured).'.');

                return null;
            }

            return $option;
        }

        $guess = $this->detectProvider();

        if ($guess !== null && in_array($guess, $configured, true)) {
            return $guess;
        }

        $this->components->error(
            'Could not determine the provider from your mail config. '
            .'Pass --provider=NAME (one of: '.implode(', ', $configured).').'
        );

        return null;
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
}
