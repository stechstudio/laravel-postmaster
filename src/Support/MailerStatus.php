<?php

namespace STS\Postmaster\Support;

use Illuminate\Support\Carbon;
use STS\Postmaster\Contracts\ProviderSetup;

/**
 * One mailer on the configuration page: how it's set up now, and what the
 * last 30 days of sends say about it. Only a mailer that's set up and has a
 * recent send confirmed by its provider's webhook counts as working; either
 * half alone is a maybe.
 */
class MailerStatus
{
    /** Transports that never reach a provider. */
    protected const array UNDELIVERED = ['log', 'array'];

    /**
     * @param string|null       $role       "Default", or a place in a failover chain.
     * @param bool              $configured Whether config/mail.php still defines it.
     * @param bool|null         $key        Whether its sending key or SMTP login is set; null when that can't be told.
     * @param bool|null         $loads      Whether its driver loaded; null when not tried.
     * @param array<string,int> $reported   Sends confirmed by webhook, by the provider that sent the webhook.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $transport,
        public readonly ?string $role,
        public readonly ?ProviderSetup $provider,
        public readonly ?string $host,
        public readonly bool $configured,
        public readonly ?bool $key,
        public readonly ?bool $loads,
        public readonly bool $syncReady,
        public readonly int $sent,
        public readonly array $reported,
        public readonly ?Carbon $lastSentAt,
        public readonly ?Carbon $lastWebhookAt,
    ) {
    }

    public function confirmed(): int
    {
        return array_sum($this->reported);
    }

    public function isSmtp(): bool
    {
        return $this->transport === 'smtp';
    }

    public function delivers(): bool
    {
        return ! in_array($this->transport, self::UNDELIVERED, true);
    }

    /** The provider's name: detected from config, or else named by its webhooks. */
    public function providerLabel(): string
    {
        if ($this->provider) {
            return $this->provider->label();
        }

        if ($this->reported) {
            return (string) array_search(max($this->reported), $this->reported, true);
        }

        return $this->delivers() ? 'Unknown' : ucfirst((string) $this->transport);
    }

    /**
     * The sending credential: [tone, label].
     *
     * @return array{0: string, 1: string}
     */
    public function credential(): array
    {
        return match (true) {
            ! $this->configured || ! $this->delivers() => ['muted', '—'],
            $this->loads === false                     => ['bad', 'Driver didn\'t load'],
            $this->isSmtp()                            => $this->key ? ['ok', 'Login set'] : ['muted', 'No login'],
            $this->key === true                        => ['ok', 'Key set'],
            $this->key === false                       => ['bad', 'Key missing'],
            default                                    => ['muted', 'Can\'t tell'],
        };
    }

    /**
     * Webhook verification: [tone, label].
     *
     * @return array{0: string, 1: string}
     */
    public function webhook(): array
    {
        return match (true) {
            $this->provider === null                => ['muted', '—'],
            $this->provider->webhookAuthConfigured() => ['ok', 'Ready'],
            default                                 => ['bad', 'Secret missing'],
        };
    }

    /**
     * Suppression sync: [tone, label].
     *
     * @return array{0: string, 1: string}
     */
    public function sync(): array
    {
        return match (true) {
            $this->provider === null                     => ['muted', '—'],
            ! $this->provider->supportsSuppressionSync() => ['muted', 'Not supported'],
            $this->syncReady                             => ['ok', 'Ready'],
            default                                      => ['warn', 'Needs key'],
        };
    }

    /**
     * The verdict: [tone, label, why].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function status(): array
    {
        $isDefault = str_starts_with((string) $this->role, 'Default');

        return match (true) {
            ! $this->configured => ['warn', 'No longer configured', "It sent mail recently, but config/mail.php has no {$this->name} mailer now."],
            ! $this->delivers() => ['muted', 'Doesn\'t deliver', $this->transport === 'log' ? 'Mail is written to the log.' : 'Mail is kept in memory.'],
            $this->loads === false => [$isDefault ? 'bad' : 'warn', 'Not set up', "Laravel couldn't load the {$this->transport} driver. Is its package installed?"],
            $this->key === false => [$isDefault ? 'bad' : 'warn', 'Not set up', 'Its sending key isn\'t in config.'],
            $this->confirmed() > 0 => ['ok', 'Working', "{$this->providerLabel()} webhooks confirmed its recent sends."],
            $this->provider === null => ['muted', 'Not tracked', "Postmaster can't tell which provider runs {$this->host}, so it can't match webhooks to its mail."],
            $this->sent > 0 => ['warn', 'No webhooks back', $this->provider->webhookAuthConfigured()
                ? "No {$this->provider->label()} webhook has updated its recent sends. Check the webhook in {$this->provider->label()}."
                : "Its webhook secret is missing, so Postmaster rejects {$this->provider->label()}'s webhooks."],
            default => ['warn', 'Unconfirmed', 'Set up, but nothing has gone through it in the last 30 days.'],
        };
    }
}
