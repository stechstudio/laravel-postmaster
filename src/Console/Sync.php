<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use STS\Postmaster\Contracts\SuppressionSync;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAddress;
use STS\Postmaster\Postmaster;
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
                            {--provider= : Sync only one provider (sendgrid, postmark, mailgun, ses, resend)}
                            {--dry-run   : Report what would change without writing anything}';

    protected $description = 'Mirror each provider\'s suppression list into the local table';

    public function handle(Postmaster $postmaster): int
    {
        $providers = $this->resolveProviders();

        if (empty($providers)) {
            $this->components->warn('No providers configured for sync.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($providers as $name) {
            $this->syncProvider($postmaster, $name, $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * The provider currently being synced — stashed so reconcile() can stamp
     * it onto each row it adds, without having to plumb the name through
     * every internal call.
     */
    protected ?string $currentProvider = null;

    /**
     * @return array<int, string>
     */
    protected function resolveProviders(): array
    {
        if ($only = $this->option('provider')) {
            return [(string) $only];
        }

        return array_keys(config('postmaster.providers', []));
    }

    protected function syncProvider(Postmaster $postmaster, string $provider, bool $dryRun): void
    {
        $sync = $postmaster->sync($provider);

        if ($sync === null) {
            $this->components->twoColumnDetail($provider, '<fg=gray>no sync class</>');

            return;
        }

        if (! $sync->isAvailable()) {
            $this->components->twoColumnDetail($provider, '<fg=gray>SDK or API key not configured — skipped</>');

            return;
        }

        try {
            $this->currentProvider = $provider;
            $remote   = $this->fetchRemote($sync);
            $local    = $this->fetchLocal();
            $written  = $this->reconcile($remote, $local, $dryRun);

            $this->components->twoColumnDetail(
                $provider,
                sprintf(
                    '<fg=green>%d added</>, <fg=yellow>%d cleared</>, <fg=gray>%d unchanged</>',
                    $written['added'],
                    $written['cleared'],
                    $written['unchanged'],
                ).($dryRun ? ' <fg=gray>(dry run)</>' : '')
            );
        } catch (Throwable $e) {
            $this->components->twoColumnDetail($provider, '<fg=red>'.$e->getMessage().'</>');
        }
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
     * @return Collection<int, EmailAddress>
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
     * @param  array<string, array{address: string, reason: string, suppressed_at: \DateTimeInterface|null}>                       $remote
     * @param  Collection<int, EmailAddress>|\Illuminate\Support\Collection<string, EmailAddress> $local
     * @return array{added: int, cleared: int, unchanged: int}
     */
    protected function reconcile(array $remote, $local, bool $dryRun): array
    {
        $stats = ['added' => 0, 'cleared' => 0, 'unchanged' => 0];

        // Provider → local: suppress any addresses the provider holds that
        // aren't suppressed locally.
        foreach ($remote as $address => $entry) {
            if (isset($local[$address])) {
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
                    ? \Illuminate\Support\Carbon::instance(\DateTimeImmutable::createFromInterface($entry['suppressed_at']))
                    : now();
                $row->status        = EmailAddress::STATUS_SUPPRESSED;
                $row->recordProvider($this->currentProvider);
                $row->save();

                // Sync mutates the row in place to preserve the provider's
                // own suppressed_at timestamp — so the activity entry is
                // written by hand here rather than through EmailAddress::
                // suppress() (which would stamp it with now()). Source =
                // 'sync' attributes the entry to the reconciliation job.
                $row->logActivity([
                    'status'   => EmailActivity::STATUS_SUPPRESSED,
                    'reason'   => $entry['reason'],
                    'provider' => $this->currentProvider,
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

            if (! in_array($row->reason, EmailAddress::AUTOMATIC_REASONS, true)) {
                continue;
            }

            if (! $dryRun) {
                // EmailAddress::unsuppress() writes the activity entry for
                // us; passing source='sync' attributes it to the sync job
                // (the provider's authoritative list no longer holds the
                // address, so we mirror that locally).
                $row->unsuppress(source: 'sync', activity: [
                    'provider' => $this->currentProvider,
                    'response' => "Cleared locally: {$this->currentProvider} no longer holds the suppression.",
                ]);
            }

            $stats['cleared']++;
        }

        return $stats;
    }
}
