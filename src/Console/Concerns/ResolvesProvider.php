<?php

namespace STS\Postmaster\Console\Concerns;

use STS\Postmaster\Contracts\ProviderSetup;
use STS\Postmaster\Postmaster;

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

    /** Just the detected provider name, or null when it can't be determined. */
    protected function detectProvider(): ?string
    {
        return app(Postmaster::class)->detectProvider()[0];
    }

    protected function webhookUrl(string $provider): string
    {
        return app(Postmaster::class)->webhookUrl($provider);
    }

    /**
     * Whether the command can actually prompt for input. This is stricter than
     * $input->isInteractive() and mirrors how Laravel decides whether Laravel
     * Prompts render: a real TTY is required. On a platform like Laravel Cloud
     * the input reports interactive while there is no TTY — so a prompt would
     * throw "Required." rather than fall back. Gating on isInteractive() first
     * keeps --no-interaction authoritative (including under test).
     */
    protected function canPrompt(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        if (app()->runningUnitTests()) {
            return true;
        }

        return defined('STDIN') && @stream_isatty(STDIN);
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

    protected function looksLocal(string $url): bool
    {
        return app(Postmaster::class)->isUnreachableUrl($url);
    }
}
