<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use STS\Postmaster\Attachments\AttachmentStatus;
use STS\Postmaster\Attachments\AttachmentStore;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailAttachment;
use STS\Postmaster\Models\EmailMessage;

/**
 * Prunes recorded persistence data past its retention windows. Runs daily
 * once persistence is enabled — and can be invoked by hand too:
 *
 *     php artisan postmaster:prune              # both content and events
 *     php artisan postmaster:prune --content    # only stored content
 *     php artisan postmaster:prune --activity   # only timeline activity
 *     php artisan postmaster:prune --attachments  # only stored attachments
 *
 * Stored content is *purged from the row* — the email_messages record is
 * kept, only its content columns are cleared.
 *
 * Timeline events are deleted as whole rows, with two retention windows:
 * routine events (sent / opened / clicked / delivered / …) and failure
 * events (bounced / dropped / complained). Failures are kept much longer
 * by default because a bounce six months ago is still useful evidence
 * when a domain misbehaves today.
 */
class Prune extends Command
{
    protected $signature = 'postmaster:prune
                            {--content     : Only purge stored content}
                            {--activity    : Only delete old timeline activity}
                            {--attachments : Only reclaim stored attachments}
                            {--dry-run     : Report what would be pruned without writing anything}';

    protected $description = 'Prune stored email content and timeline events past their retention windows';

    public function handle(): int
    {
        $named = array_keys(array_filter([
            'content'     => (bool) $this->option('content'),
            'activity'    => (bool) $this->option('activity'),
            'attachments' => (bool) $this->option('attachments'),
        ]));

        // No flags means every pass; any flag means only the named ones.
        $runs   = $named === [] ? ['content', 'activity', 'attachments'] : $named;
        $dryRun = (bool) $this->option('dry-run');

        if (in_array('content', $runs, true)) {
            $this->pruneContent($dryRun);
        }

        if (in_array('attachments', $runs, true)) {
            $this->pruneAttachments($dryRun);
            $this->evictAttachments($dryRun);
        }

        if (in_array('activity', $runs, true)) {
            $this->pruneActivity(
                'routine',
                'prune_routine_activity_after_days',
                fn ($q) => $q->whereNotIn('status', EmailMessage::FAILED_STATUSES)
                             ->orWhereNull('status'),
                $dryRun,
            );

            $this->pruneActivity(
                'failure',
                'prune_failed_activity_after_days',
                fn ($q) => $q->whereIn('status', EmailMessage::FAILED_STATUSES),
                $dryRun,
            );
        }

        return self::SUCCESS;
    }

    /**
     * Clear the heavy content columns from records older than the
     * configured retention window. Row is preserved. Under --dry-run the
     * matching rows are counted but left untouched.
     */
    protected function pruneContent(bool $dryRun): void
    {
        $days = (int) config('postmaster.persistence.prune_content_after_days');

        if ($days <= 0) {
            $this->components->twoColumnDetail('Stored content', '<fg=gray>pruning disabled</>');

            return;
        }

        $query = EmailMessage::model()->newQuery()
            ->where('created_at', '<', now()->subDays($days))
            ->where(function ($query) {
                $query->whereNotNull('html_body')
                    ->orWhereNotNull('text_body')
                    ->orWhereNotNull('from_address')
                    ->orWhereNotNull('recipients')
                    ->orWhereNotNull('legacy_attachment_names');
            });

        $count = $dryRun
            ? $query->count()
            : $query->update([
                'from_address' => null,
                'recipients'   => null,
                'html_body'    => null,
                'text_body'    => null,
                'legacy_attachment_names' => null,
            ]);

        $this->components->twoColumnDetail(
            'Stored content',
            ($dryRun ? 'would purge' : 'purged')." from {$count} ".Str::plural('message', $count)
                .($dryRun ? ' <fg=gray>(dry run)</>' : '')
        );
    }

    /**
     * Reclaim the bytes of attachments past their retention window. The
     * metadata row survives, marked Pruned, so the dashboard can still say
     * what the email carried.
     */
    protected function pruneAttachments(bool $dryRun): void
    {
        $days = (int) config('postmaster.persistence.attachments.prune_after_days');

        if ($days <= 0) {
            $this->components->twoColumnDetail('Stored attachments', '<fg=gray>pruning disabled</>');

            return;
        }

        $expired = EmailAttachment::model()->newQuery()
            ->where('status', AttachmentStatus::Stored)
            ->where('created_at', '<', now()->subDays($days))
            ->get();

        $freed = 0;

        if (! $dryRun) {
            $store = app(AttachmentStore::class);

            foreach ($expired as $attachment) {
                $freed += $store->forget($attachment, AttachmentStatus::Pruned);
            }
        }

        $this->components->twoColumnDetail(
            'Stored attachments',
            ($dryRun ? 'would reclaim' : 'reclaimed')." {$expired->count()} "
                .Str::plural('attachment', $expired->count())
                .($dryRun ? ' <fg=gray>(dry run)</>' : ' ('.EmailAttachment::humanBytes($freed).')')
        );
    }

    /**
     * Reclaim the least-recently-referenced attachments until disk usage fits
     * the configured ceiling.
     *
     * Works on checksum *groups* rather than rows, because content-addressed
     * storage means a file is only reclaimed when every reference to it goes —
     * evicting row by row would free nothing and loop forever.
     *
     * Metadata rows survive, marked Evicted, so the dashboard reports
     * "invoice.pdf, 2.1 MB, evicted Aug 1" rather than serving a dead link.
     */
    protected function evictAttachments(bool $dryRun): void
    {
        $ceiling = (int) config('postmaster.persistence.attachments.max_disk_usage');

        if ($ceiling <= 0) {
            $this->components->twoColumnDetail('Attachment disk budget', '<fg=gray>unbounded</>');

            return;
        }

        $store    = app(AttachmentStore::class);
        $usage    = $store->usage();
        $freed    = 0;
        $count    = 0;
        $examined = [];

        while ($usage > $ceiling) {
            $checksum = EmailAttachment::model()->newQuery()
                ->where('status', AttachmentStatus::Stored)
                ->whereNotIn('checksum', $examined)
                ->groupBy('checksum')
                ->orderByRaw('max(created_at) asc')
                ->value('checksum');

            if ($checksum === null) {
                break;
            }

            // Tracked in both modes: under --dry-run nothing changes on disk,
            // so without this the same group would be selected forever.
            $examined[] = $checksum;

            $bytes = $dryRun
                ? $store->sizeOf($checksum)
                : $store->forgetChecksum($checksum, AttachmentStatus::Evicted);

            $freed += $bytes;
            $usage -= $bytes;
            $count++;
        }

        $this->components->twoColumnDetail(
            'Attachment disk budget',
            ($dryRun ? 'would evict' : 'evicted')." {$count} ".Str::plural('file', $count)
                .' ('.EmailAttachment::humanBytes($freed).')'
                .($dryRun ? ' <fg=gray>(dry run)</>' : '')
        );
    }

    /**
     * Delete one bucket of activity entries older than its retention window.
     *
     * @param string   $label     Bucket name, for the output line.
     * @param string   $configKey The retention-days config key on persistence.
     * @param \Closure $scope     Applies the bucket's status filter to a query.
     * @param bool     $dryRun    Count the matching rows instead of deleting.
     */
    protected function pruneActivity(string $label, string $configKey, \Closure $scope, bool $dryRun): void
    {
        $days = (int) config("postmaster.persistence.{$configKey}");

        if ($days <= 0) {
            $this->components->twoColumnDetail(ucfirst($label).' activity', '<fg=gray>pruning disabled</>');

            return;
        }

        $query = EmailActivity::model()->newQuery()
            ->where('occurred_at', '<', now()->subDays($days))
            ->where(fn ($query) => $scope($query));

        $count = $dryRun ? $query->count() : $query->delete();

        $this->components->twoColumnDetail(
            ucfirst($label).' activity',
            ($dryRun ? 'would delete' : 'deleted')." {$count} ".Str::plural('entry', $count)
                .($dryRun ? ' <fg=gray>(dry run)</>' : '')
        );
    }
}
