<?php

namespace STS\Postmaster\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use STS\Postmaster\Contracts\SuppressionSync;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Postmaster;
use ReflectionClass;
use Throwable;

/**
 * Reconciles every configured provider's suppression list with the
 * package's local email_addresses table.
 *
 * Each provider's SDK is a soft dependency: when it isn't installed, the
 * sync for that provider is skipped with a hint. Manual suppressions in
 * the local table (reason = 'manual') are never auto-cleared — operators'
 * decisions stand even when the provider's list disagrees.
 *
 * Scheduled daily at 04:00 by the package; can be run by hand any time.
 */
class Sync extends Command
{
    protected $signature = 'postmaster:sync
                            {--provider= : Sync only one provider (sendgrid, postmark, mailgun, ses, resend, helo)}
                            {--dry-run   : Report what would change without writing anything}';

    protected $description = 'Mirror each provider\'s suppression list into the local table';

    public function handle(Postmaster $postmaster): int
    {
        $configured = array_keys(config('postmaster.providers', []));
        $only       = $this->option('provider') ?: null;

        if ($only !== null && ! in_array($only, $configured, true)) {
            $this->components->error(
                "Unknown provider \"{$only}\". Configured: ".($configured === [] ? 'none' : implode(', ', $configured)).'.'
            );

            return self::FAILURE;
        }

        $providers = $only !== null ? [$only] : $configured;

        if ($providers === []) {
            $this->components->warn('No providers configured for sync.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $successful = true;
        foreach ($providers as $provider) {
            $successful = $this->syncProvider($postmaster, $provider, $dryRun) && $successful;
        }

        return $successful ? self::SUCCESS : self::FAILURE;
    }

    protected function syncProvider(Postmaster $postmaster, string $provider, bool $dryRun): bool
    {
        $sync = $postmaster->sync($provider);

        if ($sync === null) {
            $this->components->twoColumnDetail($provider, '<fg=gray>no sync class</>');

            return true;
        }

        if (! $sync->isAvailable()) {
            $this->components->twoColumnDetail($provider, '<fg=gray>API credentials, scope, or optional SDK not configured — skipped</>');

            return true;
        }

        try {
            $remote = $this->fetchRemote($sync);
        } catch (Throwable $e) {
            $this->components->twoColumnDetail($provider, '<fg=red>'.$e->getMessage().'</>');

            return false;
        }

        $written = $this->reconcile($provider, $remote, $this->fetchLocal(), $dryRun);
        $this->components->twoColumnDetail(
            $provider,
            sprintf(
                '<fg=green>%d added</>, <fg=yellow>%d cleared</>, <fg=gray>%d unchanged</>',
                $written['added'], $written['cleared'], $written['unchanged'],
            ).($dryRun ? ' <fg=gray>(dry run)</>' : '')
        );

        return true;
    }

    /**
     * The product name webhooks record ("SendGrid", "SES"), so sync and
     * webhooks attribute a suppression to the same provider.
     */
    protected function providerName(string $provider): string
    {
        $adapter = config("postmaster.providers.{$provider}.adapter");

        // Skip the constructor: Mailgun's and SES's unwrap a webhook payload.
        return is_string($adapter) && class_exists($adapter)
            ? (new ReflectionClass($adapter))->newInstanceWithoutConstructor()->provider()
            : $provider;
    }

    /**
     * Pull the provider's full suppression list into memory, keyed by
     * lowercased address.
     *
     * @return array<string, array{address: string, reason: string, suppressed_at: \DateTimeInterface|null}>
     */
    protected function fetchRemote(SuppressionSync $sync): array
    {
        $remote = [];

        foreach ($sync->pull() as $entry) {
            $remote[strtolower($entry['address'])] = $entry;
        }

        return $remote;
    }

    /**
     * Existing suppression rows in the local table, keyed by address.
     *
     * @return Collection<string, EmailAddress>
     */
    protected function fetchLocal(): Collection
    {
        return EmailAddress::model()->newQuery()
            ->where('status', EmailAddress::STATUS_SUPPRESSED)
            ->get()
            ->keyBy('address');
    }

    /**
     * Apply the diff between provider and local. Returns counts for the
     * summary line.
     *
     * @param  array<string, array{address: string, reason: string, suppressed_at: \DateTimeInterface|null}> $remote
     * @param  Collection<string, EmailAddress>                                                              $local
     * @return array{added: int, cleared: int, unchanged: int}
     */
    protected function reconcile(string $key, array $remote, Collection $local, bool $dryRun): array
    {
        $stats = ['added' => 0, 'cleared' => 0, 'unchanged' => 0];
        $provider = $this->providerName($key);

        // Provider → local: suppress any addresses the provider holds that
        // aren't suppressed locally.
        foreach ($remote as $address => $entry) {
            if (isset($local[$address])) {
                if (! $dryRun) {
                    $row = $local[$address];
                    $row->recordProvider($provider);
                    // An opt-out must survive delivery-based auto-clearing.
                    // Manual decisions and complaints remain stronger reasons.
                    if ($entry['reason'] === EmailAddress::REASON_UNSUBSCRIBED
                        && in_array($row->reason, [EmailAddress::REASON_BOUNCED, EmailAddress::REASON_DROPPED], true)) {
                        $row->reason = $entry['reason'];
                    }
                    if ($row->isDirty()) {
                        $row->save();
                    }
                }
                $stats['unchanged']++;
                continue;
            }

            if (! $dryRun) {
                $row = EmailAddress::model()->newQuery()->firstOrNew(['address' => $address]);
                $row->reason        = $entry['reason'];
                // Cast the provider's DateTimeInterface to a Carbon so it
                // matches the model's typed property. Eloquent's datetime
                // cast handles either at write time, but the typed property
                // narrows the in-memory value.
                $row->suppressed_at = $entry['suppressed_at']
                    ? Carbon::instance(DateTimeImmutable::createFromInterface($entry['suppressed_at']))
                    : now();
                $row->status        = EmailAddress::STATUS_SUPPRESSED;
                $row->recordProvider($provider);
                $row->save();

                // Sync mutates the row in place to preserve the provider's
                // own suppressed_at timestamp — so the activity entry is
                // written by hand here rather than through EmailAddress::
                // suppress() (which would stamp it with now()). Source =
                // 'sync' attributes the entry to the reconciliation job.
                $row->logActivity([
                    'status'   => EmailActivity::STATUS_SUPPRESSED,
                    'reason'   => $entry['reason'],
                    'provider' => $provider,
                    'source'   => 'sync',
                ]);
            }

            $stats['added']++;
        }

        // Local → provider: any locally-suppressed row whose reason is one
        // of AUTOMATIC_REASONS but which the provider no longer holds
        // should be cleared. Manual suppressions are never auto-cleared.
        foreach ($local as $address => $row) {
            if (isset($remote[$address])) {
                continue;
            }

            // A provider's empty list says nothing about other providers.
            // With several recorded sources, retain the global suppression
            // until an operator reconciles them explicitly.
            // Older syncs recorded the config key rather than the product name.
            $sources = array_unique(array_map(
                fn ($source) => strcasecmp($source, $key) === 0 ? strtolower($provider) : strtolower($source),
                $row->providers ?? [],
            ));
            if ($sources !== [] && $sources !== [strtolower($provider)]) {
                continue;
            }

            if (! in_array($row->reason, EmailAddress::AUTOMATIC_REASONS, true)) {
                continue;
            }

            if (! $dryRun) {
                // EmailAddress::unsuppress() writes the activity entry for
                // us; passing source='sync' attributes it to the sync job
                // (the provider's authoritative list no longer holds the
                // address, so we mirror that locally).
                $row->unsuppress(source: 'sync', activity: [
                    'provider' => $provider,
                    'response' => "Cleared locally: {$provider} no longer holds the suppression.",
                ]);
            }

            $stats['cleared']++;
        }

        return $stats;
    }
}
