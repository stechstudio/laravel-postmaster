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
}
