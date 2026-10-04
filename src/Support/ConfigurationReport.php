<?php

namespace STS\Postmaster\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use STS\Postmaster\Contracts\ProviderSetup;
use STS\Postmaster\EmailEvent;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Postmaster;
use STS\Postmaster\PostmasterServiceProvider;

/**
 * What the dashboard's configuration page shows: which mailers send and
 * whether their providers report back, how webhooks come back, what
 * Postmaster records and for how long, and any settings that won't do what
 * they appear to. Reads config live and never returns a credential's value,
 * only whether it is set.
 *
 * A row is ['label', 'value', 'env', 'note', 'tone', 'mono']. A tone renders
 * the value as a badge.
 */
class ConfigurationReport
{
    /** How far back sends count as evidence that a mailer works. */
    public const int RECENT_DAYS = 30;

    /** Transports that only route to other mailers. */
    protected const array CHAINS = ['failover', 'roundrobin'];

    /** @var array<string, ProviderSetup> */
    protected array $setups = [];

    /** @var list<MailerStatus>|null */
    protected ?array $mailers = null;

    public function __construct(protected Postmaster $postmaster)
    {
        foreach (array_keys(config('postmaster.providers', [])) as $name) {
            $this->setups[$name] = $postmaster->setup($name);
        }
    }

    /**
     * The mailers worth showing: the default (or each mailer in its chain),
     * any other mailer whose key or login is set, and any mailer that sent
     * mail in the last 30 days. Laravel's stock mailers that nobody set up
     * stay out.
     *
     * @return list<MailerStatus>
     */
    public function mailers(): array
    {
        return $this->mailers ??= $this->buildMailers();
    }

    /** The mailer mail goes through by default: the default, or the first in its chain. */
    public function defaultMailer(): ?MailerStatus
    {
        foreach ($this->mailers() as $mailer) {
            if (str_starts_with((string) $mailer->role, 'Default')) {
                return $mailer;
            }
        }

        return null;
    }

    /** Whether recent mail was sent before Postmaster started recording each message's mailer. */
    public function hasSendsWithoutMailer(): bool
    {
        return EmailMessage::model()->newQuery()->withoutGlobalScopes()
            ->where('created_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->whereNull('mailer')
            ->whereNotNull('sent_at')
            ->exists();
    }

    /**
     * The headline tiles: [label, value, tone, caption].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public function summary(): array
    {
        $default = $this->defaultMailer();

        return [
            $this->isSandboxed()
                ? ['Delivery', 'Sandbox', 'warn', 'Mail is recorded, not sent']
                : ['Delivery', 'Normal', 'ok', 'Mail is sent as usual'],
            $default
                ? ['Sending through', $default->providerLabel(), $default->status()[0], "{$default->status()[1]} · {$default->name} mailer"]
                : ['Sending through', 'Unknown', 'muted', 'No default mailer'],
            ['Timeline', $this->recordsEvents() ? 'Recording' : 'Off', $this->recordsEvents() ? 'plain' : 'muted', $this->recordsEvents() ? 'Every webhook event is kept' : 'Only the latest status is kept'],
            config('postmaster.queue_webhooks')
                ? ['Webhooks', 'Queued', 'plain', 'On the '.$this->webhookQueueConnection().' connection']
                : ['Webhooks', 'Inline', 'plain', 'Handled during the request'],
        ];
    }

    /**
     * Settings that need attention, as [tone, title, body].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function checks(): array
    {
        $checks    = [];
        $default   = $this->defaultMailer();
        $providers = $this->providers();

        if ($this->isSandboxed()) {
            $checks[] = ['warn', 'Sandbox is on', 'No mail leaves this app. Postmaster records each message as sandboxed instead of sending it. Set POSTMASTER_DELIVERY=normal to send for real.'];
        }

        foreach ($providers as $provider) {
            if (! $provider->webhookAuthConfigured()) {
                $checks[] = ['bad', "{$provider->label()} webhook secret missing", "Postmaster rejects every {$provider->label()} webhook until it can verify them. ".$provider->webhookAuthGuidance()[0]];
            }
        }

        if ($default && ! $default->provider && ! $default->confirmed()) {
            $checks[] = $default->delivers()
                ? ['info', 'Sending provider not detected', "Postmaster can't tell which provider the {$default->name} mailer sends through, so it can't confirm that its mail is delivered."]
                : ['info', "Mail goes to the {$default->transport} mailer", 'Nothing is delivered, so no provider will send webhooks.'];
        }

        if ($providers && $this->postmaster->isUnreachableUrl($url = $this->postmaster->webhookUrl($providers[0]->name()))) {
            $checks[] = [app()->isLocal() ? 'info' : 'warn', 'Webhook URL is not public', "Providers can't reach {$url}. Set APP_URL to the app's public address, or use a tunnel while testing."];
        }

        if (config('postmaster.block_suppressed') && ! config('postmaster.persistence.track_addresses')) {
            $checks[] = ['warn', 'Address tracking is off', 'Webhooks never suppress an address, so blocking suppressed recipients only catches addresses that postmaster:sync pulls from your provider. Set POSTMASTER_TRACK_ADDRESSES=true.'];
        }

        if (config('postmaster.queue_webhooks') && config('queue.connections.'.$this->webhookQueueConnection().'.driver') === 'sync') {
            $checks[] = ['info', 'Webhooks are not really queued', 'POSTMASTER_QUEUE_WEBHOOKS is on, but the sync queue connection runs them inline. Point POSTMASTER_QUEUE_CONNECTION at a real queue.'];
        }

        if (config('postmaster.persistence.store_content') && $this->days('prune_content_after_days') === null) {
            $checks[] = ['warn', 'Stored message content is never deleted', 'Message bodies stay in the database for good. Set POSTMASTER_PRUNE_CONTENT_AFTER_DAYS to clear them after a while.'];
        }

        foreach ($providers as $provider) {
            if ($provider->supportsSuppressionSync() && ! $this->syncReady($provider)) {
                $checks[] = ['info', "{$provider->label()} suppressions don't sync", "postmaster:sync skips {$provider->label()} until its API key is set, so addresses it suppresses never reach this app."];
            }
        }

        return $checks;
    }

    /**
     * The settings, grouped: title => [description, rows].
     *
     * @return array<string, array{0: string, 1: list<array<string, mixed>>}>
     */
    public function sections(): array
    {
        return [
            'Sending'   => ['How mail leaves the app.', $this->sending()],
            'Webhooks'  => ['How providers report back.', $this->webhooks()],
            'Recording' => ['What Postmaster keeps in the database.', $this->recording()],
            'Retention' => ['How long Postmaster keeps each kind of record.', $this->retention()],
        ];
    }

    protected function sending(): array
    {
        return [
            $this->row('Default mailer', (string) config('mail.default'), 'MAIL_MAILER', mono: true),
            $this->from(),
            $this->row('Delivery', ucfirst((string) config('postmaster.delivery', 'normal')), 'POSTMASTER_DELIVERY', tone: $this->isSandboxed() ? 'warn' : 'ok'),
            $this->toggle('Block suppressed recipients', config('postmaster.block_suppressed'), 'POSTMASTER_BLOCK_SUPPRESSED', 'Sends to a suppressed address are recorded as blocked and never handed to the mailer.'),
            $this->row('Suppression sync', 'Daily at '.PostmasterServiceProvider::SYNC_AT, note: 'Runs postmaster:sync to pull each provider\'s suppression list. Needs Laravel\'s scheduler running.'),
        ];
    }

    /** The sender's name as the value and the address as its note, so neither breaks mid-word. */
    protected function from(): array
    {
        $address = config('mail.from.address');
        $name    = config('mail.from.name');

        return match (true) {
            ! $address => $this->row('From', 'Not set', 'MAIL_FROM_ADDRESS', tone: 'warn'),
            ! $name    => $this->row('From', (string) $address, 'MAIL_FROM_ADDRESS'),
            default    => $this->row('From', (string) $name, 'MAIL_FROM_ADDRESS', (string) $address),
        };
    }

    protected function webhooks(): array
    {
        $providers = $this->providers();
        $endpoints = array_map(fn (ProviderSetup $provider) => $this->row(
            count($providers) > 1 ? "{$provider->label()} endpoint" : 'Endpoint',
            $this->postmaster->webhookUrl($provider->name()),
            'APP_URL',
            mono: true,
        ), $providers);

        return [
            ...$endpoints,
            $this->toggle('Route registered', $registers = config('postmaster.register_route', true), 'POSTMASTER_REGISTER_ROUTE', $registers
                ? 'Postmaster registers the webhook route.'
                : 'The app registers the webhook route itself.'),
            config('postmaster.queue_webhooks')
                ? $this->row('Processing', 'Queued', 'POSTMASTER_QUEUE_WEBHOOKS', 'Connection '.$this->webhookQueueConnection().', queue '.(config('postmaster.queue_name') ?: 'default').'.', 'info')
                : $this->row('Processing', 'Inline', 'POSTMASTER_QUEUE_WEBHOOKS', 'Each webhook is handled during its request.', 'muted'),
            $this->row('Unreadable payloads', match (config('postmaster.on_invalid', 'log')) {
                'throw'  => 'Throw an exception',
                'ignore' => 'Ignore silently',
                default  => 'Log a warning',
            }, 'POSTMASTER_ON_INVALID'),
        ];
    }

    protected function recording(): array
    {
        $connection  = config('postmaster.persistence.connection') ?: config('database.default');
        $attachments = (array) config('postmaster.persistence.attachments', []);

        return [
            $this->row('Database', $connection === ($driver = config("database.connections.{$connection}.driver")) ? $connection : "{$connection} ({$driver})", 'POSTMASTER_PERSISTENCE_CONNECTION', mono: true),
            $this->toggle('Timeline', $this->recordsEvents(), 'POSTMASTER_RECORD_EVENTS', 'Keeps every webhook event, not just each message\'s latest status.'),
            $this->toggle('Address tracking', config('postmaster.persistence.track_addresses'), 'POSTMASTER_TRACK_ADDRESSES', 'Suppresses an address when a provider reports a hard bounce, complaint, or unsubscribe.'),
            $this->toggle('Message content', config('postmaster.persistence.store_content'), 'POSTMASTER_STORE_CONTENT', 'Keeps subject, bodies, and recipients so you can read and resend a message.'),
            $this->toggle('Attachments', $attachments['store'] ?? false, 'POSTMASTER_STORE_ATTACHMENTS', ($attachments['store'] ?? false)
                ? 'On the '.($attachments['disk'] ?? 'local').' disk under '.($attachments['path'] ?? '').', up to '.$this->bytes($attachments['max_size'] ?? null).' each.'
                : null),
            $this->toggle('Tenancy', $this->postmaster->resolvesTenants(), null, $this->postmaster->resolvesTenants()
                ? 'Messages are stamped with the current tenant in the '.config('postmaster.persistence.tenant_column', 'tenant_id').' column.'
                : 'No tenant resolver registered.'),
        ];
    }

    protected function retention(): array
    {
        $storesAttachments = config('postmaster.persistence.attachments.store');

        return [
            $this->window('Message content', 'prune_content_after_days', 'POSTMASTER_PRUNE_CONTENT_AFTER_DAYS', config('postmaster.persistence.store_content') ? null : 'Content isn\'t stored.'),
            $this->window('Attachment files', 'attachments.prune_after_days', 'POSTMASTER_PRUNE_ATTACHMENTS_AFTER_DAYS', $storesAttachments ? null : 'Attachments aren\'t stored.'),
            $this->row('Attachment disk cap', $this->bytes(config('postmaster.persistence.attachments.max_disk_usage')) ?: 'No cap', 'POSTMASTER_ATTACHMENTS_MAX_DISK_USAGE', $storesAttachments ? 'Oldest files go first once the cap is reached.' : null),
            $this->window('Routine events', 'prune_routine_activity_after_days', 'POSTMASTER_PRUNE_ROUTINE_ACTIVITY_AFTER_DAYS', 'Sends, deliveries, opens, and clicks.'),
            $this->window('Failure events', 'prune_failed_activity_after_days', 'POSTMASTER_PRUNE_FAILED_ACTIVITY_AFTER_DAYS', 'Bounces, complaints, drops, and suppressions.'),
            $this->row('Pruning', 'Daily at '.PostmasterServiceProvider::PRUNE_AT, note: 'Runs postmaster:prune. Needs Laravel\'s scheduler running.'),
        ];
    }

    /**
     * Escape text and set any .env name or artisan command in it in mono, for
     * check bodies, notes, and reasons that mention them.
     */
    public static function markup(string $text): HtmlString
    {
        return new HtmlString(preg_replace(
            '/\b([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+|postmaster:[a-z-]+)\b/',
            '<span class="pm-mono">$1</span>',
            e($text),
        ));
    }

    protected function row(string $label, string $value, ?string $env = null, ?string $note = null, ?string $tone = null, bool $mono = false): array
    {
        return compact('label', 'value', 'env', 'note', 'tone', 'mono');
    }

    protected function toggle(string $label, mixed $on, ?string $env, ?string $note = null): array
    {
        return $this->row($label, $on ? 'On' : 'Off', $env, $note, $on ? 'ok' : 'muted');
    }

    protected function window(string $label, string $key, string $env, ?string $note): array
    {
        $days = $this->days($key);

        return $this->row($label, $days === null ? 'Forever' : ($days === 1 ? '1 day' : "{$days} days"), $env, $note, $days === null ? 'muted' : null);
    }

    /** A retention window in days, or null when it never prunes. */
    protected function days(string $key): ?int
    {
        $days = (int) config("postmaster.persistence.{$key}");

        return $days > 0 ? $days : null;
    }

    protected function bytes(mixed $bytes): string
    {
        $bytes = (int) $bytes;

        return match (true) {
            $bytes <= 0                  => '',
            $bytes >= 1024 ** 3          => round($bytes / 1024 ** 3, 1).' GB',
            $bytes >= 1024 ** 2          => round($bytes / 1024 ** 2, 1).' MB',
            default                      => round($bytes / 1024, 1).' KB',
        };
    }

    protected function syncReady(ProviderSetup $setup): bool
    {
        return (bool) $this->postmaster->sync($setup->name())?->isAvailable();
    }

    /**
     * The distinct providers behind the listed mailers that are still set up.
     *
     * @return list<ProviderSetup>
     */
    protected function providers(): array
    {
        $providers = [];
        foreach ($this->mailers() as $mailer) {
            if ($mailer->configured && $mailer->provider) {
                $providers[$mailer->provider->name()] = $mailer->provider;
            }
        }

        return array_values($providers);
    }

    /** @return list<MailerStatus> */
    protected function buildMailers(): array
    {
        $config = (array) config('mail.mailers', []);
        $usage  = $this->recentUsage();
        $roles  = [];

        $chain = $this->members((string) config('mail.default'));
        foreach ($chain as $i => $name) {
            $roles[$name] = count($chain) > 1 ? 'Default, '.$this->ordinal($i + 1) : 'Default';
        }

        // Sends through a chain belong to the member whose provider
        // confirmed them, or else to the first member.
        $stats = [];
        foreach ($usage as $row) {
            $members = $this->members($row->mailer);
            $target  = $members[0];

            foreach ($members as $member) {
                if ($row->provider && strtolower($row->provider) === $this->postmaster->detectProvider($member)[0]) {
                    $target = $member;
                }
            }

            if (count($members) > 1 && ! isset($roles[$target])) {
                $roles[$target] = $row->mailer.', '.$this->ordinal((int) array_search($target, $members, true) + 1);
            }

            $stats[$target]['sent'] = ($stats[$target]['sent'] ?? 0) + (int) $row->aggregate;
            $stats[$target]['last'] = max($stats[$target]['last'] ?? '', (string) $row->last_sent);
            if ($row->provider) {
                $stats[$target]['reported'][$row->provider] = ($stats[$target]['reported'][$row->provider] ?? 0) + (int) $row->aggregate;
            }
        }

        $names = array_keys($roles + $stats);
        foreach ($config as $name => $mailer) {
            if (! in_array($name, $names, true) && $this->hasCredential($name, (array) $mailer)) {
                $names[] = $name;
            }
        }

        $webhooks = $this->lastWebhooks();
        $mailers  = [];

        foreach ($names as $name) {
            $mailer    = isset($config[$name]) ? (array) $config[$name] : null;
            $transport = $mailer['transport'] ?? null;
            $key       = $mailer ? $this->sendingKey($name, $mailer) : null;
            $reported  = $stats[$name]['reported'] ?? [];
            // Config names the provider; failing that, its webhooks do.
            $provider  = ($mailer ? $this->postmaster->detectProvider($name)[0] : null)
                ?? ($reported ? strtolower((string) array_search(max($reported), $reported, true)) : null);
            $setup     = $this->setups[(string) $provider] ?? null;

            $mailers[] = new MailerStatus(
                name: $name,
                transport: $transport,
                role: $roles[$name] ?? null,
                provider: $setup,
                host: $transport === 'smtp' ? ($mailer['host'] ?? '').(isset($mailer['port']) ? ':'.$mailer['port'] : '') : null,
                configured: $mailer !== null,
                key: $key,
                loads: $mailer && $key !== false && ! in_array($transport, ['smtp', 'log', 'array', ...self::CHAINS], true)
                    ? rescue(fn () => (bool) Mail::mailer($name)->getSymfonyTransport(), false, false)
                    : null,
                syncReady: $setup !== null && $this->syncReady($setup),
                sent: $stats[$name]['sent'] ?? 0,
                reported: $reported,
                lastSentAt: isset($stats[$name]['last']) ? Carbon::parse($stats[$name]['last']) : null,
                lastWebhookAt: isset($webhooks[(string) $provider]) ? Carbon::parse($webhooks[(string) $provider]) : null,
            );
        }

        // The default chain in order, then the rest by name, then the ones gone from config.
        $order = array_flip($chain);
        usort($mailers, fn (MailerStatus $a, MailerStatus $b) => [$order[$a->name] ?? PHP_INT_MAX, ! $a->configured, $a->name]
            <=> [$order[$b->name] ?? PHP_INT_MAX, ! $b->configured, $b->name]);

        return $mailers;
    }

    /**
     * Recent sends that left the app, grouped by mailer and by the provider
     * whose webhook updated them (null when none has).
     *
     * @return Collection<int, \stdClass>
     */
    protected function recentUsage(): Collection
    {
        return EmailMessage::model()->newQuery()->withoutGlobalScopes()
            ->where('created_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->whereNotNull('mailer')
            ->whereNotIn('status', [EmailEvent::STATUS_SANDBOXED, EmailEvent::STATUS_BLOCKED])
            ->selectRaw('mailer, provider, count(*) as aggregate, max(created_at) as last_sent')
            ->groupBy('mailer', 'provider')
            ->toBase()
            ->get();
    }

    /**
     * When each provider's latest webhook arrived, keyed by lowercase provider name.
     *
     * @return array<string, string>
     */
    protected function lastWebhooks(): array
    {
        return EmailActivity::model()->newQuery()->withoutGlobalScopes()
            ->whereNotNull('provider')
            ->selectRaw('provider, max(created_at) as last_at')
            ->groupBy('provider')
            ->pluck('last_at', 'provider')
            ->mapWithKeys(fn ($at, $provider) => [strtolower($provider) => $at])
            ->all();
    }

    /**
     * The mailers a mailer sends through: its chain, or itself.
     *
     * @return non-empty-list<string>
     */
    protected function members(string $name): array
    {
        $mailer = (array) config("mail.mailers.{$name}", []);

        return in_array($mailer['transport'] ?? null, self::CHAINS, true) && ! empty($mailer['mailers'])
            ? array_values((array) $mailer['mailers'])
            : [$name];
    }

    /** Whether a mailer's sending key or SMTP login is set; null when that can't be told. */
    protected function sendingKey(string $name, array $mailer): ?bool
    {
        if (($mailer['transport'] ?? null) === 'smtp') {
            // Some relays allow by IP instead, so no login isn't proof of anything.
            return filled($mailer['username'] ?? null) ?: null;
        }

        $provider = $this->postmaster->detectProvider($name)[0];

        return $provider ? ($this->setups[$provider] ?? null)?->sendingKeySet($mailer) : null;
    }

    /** Whether a mailer nobody named as default is clearly set up to send. */
    protected function hasCredential(string $name, array $mailer): bool
    {
        return ! in_array($mailer['transport'] ?? null, ['log', 'array', 'sendmail', ...self::CHAINS], true)
            && $this->sendingKey($name, $mailer) === true;
    }

    protected function ordinal(int $n): string
    {
        return $n.match ($n) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
    }

    protected function webhookQueueConnection(): string
    {
        return (string) (config('postmaster.queue_connection') ?: config('queue.default'));
    }

    protected function isSandboxed(): bool
    {
        return config('postmaster.delivery') === 'sandbox';
    }

    protected function recordsEvents(): bool
    {
        return (bool) config('postmaster.persistence.record_events', true);
    }
}
