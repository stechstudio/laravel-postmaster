<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use STS\Postmaster\Models\EmailActivity;
use STS\Postmaster\Models\EmailMessage;

/**
 * Prunes recorded persistence data past its retention windows. Runs daily
 * once persistence is enabled — and can be invoked by hand too:
 *
 *     php artisan postmaster:prune              # both content and events
 *     php artisan postmaster:prune --content    # only stored content
 *     php artisan postmaster:prune --activity   # only timeline activity
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
                            {--content  : Only purge stored content}
                            {--activity : Only delete old timeline activity}
                            {--dry-run  : Report what would be pruned without writing anything}';

    protected $description = 'Prune stored email content and timeline events past their retention windows';

    public function handle(): int
    {
        $only = ($this->option('content') xor $this->option('activity'));
        $runContent  = ! $only || $this->option('content');
        $runActivity = ! $only || $this->option('activity');
        $dryRun      = (bool) $this->option('dry-run');

        if ($runContent) {
            $this->pruneContent($dryRun);
        }

        if ($runActivity) {
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
                    ->orWhereNotNull('attachments');
            });

        $count = $dryRun
            ? $query->count()
            : $query->update([
                'from_address' => null,
                'recipients'   => null,
                'html_body'    => null,
                'text_body'    => null,
                'attachments'  => null,
            ]);

        $this->components->twoColumnDetail(
            'Stored content',
            ($dryRun ? 'would purge' : 'purged')." from {$count} ".Str::plural('message', $count)
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
