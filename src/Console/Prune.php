<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use STS\Postmaster\Models\EmailMessage;
use STS\Postmaster\Models\EmailMessageEvent;

/**
 * Prunes recorded persistence data past its retention windows. Runs daily
 * once persistence is enabled — and can be invoked by hand too:
 *
 *     php artisan postmaster:prune              # both content and events
 *     php artisan postmaster:prune --content    # only stored content
 *     php artisan postmaster:prune --events     # only timeline events
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
                            {--content : Only purge stored content}
                            {--events  : Only delete old timeline events}';

    protected $description = 'Prune stored email content and timeline events past their retention windows';

    public function handle(): int
    {
        $only = ($this->option('content') xor $this->option('events'));
        $runContent = ! $only || $this->option('content');
        $runEvents  = ! $only || $this->option('events');

        if ($runContent) {
            $this->pruneContent();
        }

        if ($runEvents) {
            $this->pruneEvents(
                'routine',
                'prune_routine_events_after_days',
                fn ($q) => $q->whereNotIn('status', EmailMessage::FAILED_STATUSES)
                             ->orWhereNull('status'),
            );

            $this->pruneEvents(
                'failure',
                'prune_failed_events_after_days',
                fn ($q) => $q->whereIn('status', EmailMessage::FAILED_STATUSES),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Clear the heavy content columns from records older than the
     * configured retention window. Row is preserved.
     */
    protected function pruneContent(): void
    {
        $days = (int) config('postmaster.persistence.prune_content_after_days');

        if ($days <= 0) {
            $this->line('Content pruning is disabled (persistence.prune_content_after_days).');

            return;
        }

        $class = config('postmaster.persistence.model', EmailMessage::class);

        $pruned = (new $class)->newQuery()
            ->where('created_at', '<', now()->subDays($days))
            ->where(function ($query) {
                $query->whereNotNull('html_body')
                    ->orWhereNotNull('text_body')
                    ->orWhereNotNull('from_address')
                    ->orWhereNotNull('recipients')
                    ->orWhereNotNull('attachments');
            })
            ->update([
                'from_address' => null,
                'recipients'   => null,
                'html_body'    => null,
                'text_body'    => null,
                'attachments'  => null,
            ]);

        $this->info("Pruned stored content from {$pruned} email message(s).");
    }

    /**
     * Delete one bucket of timeline events older than its retention window.
     *
     * @param string   $label     Bucket name, for the output line.
     * @param string   $configKey The retention-days config key on persistence.
     * @param \Closure $scope     Applies the bucket's status filter to a query.
     */
    protected function pruneEvents( string $label, string $configKey, \Closure $scope ): void
    {
        $days = (int) config("postmaster.persistence.{$configKey}");

        if ($days <= 0) {
            $this->line("Pruning of {$label} events is disabled (persistence.{$configKey}).");

            return;
        }

        $class = config('postmaster.persistence.event_model', EmailMessageEvent::class);

        $pruned = (new $class)->newQuery()
            ->where('occurred_at', '<', now()->subDays($days))
            ->where(fn ($query) => $scope($query))
            ->delete();

        $this->info("Pruned {$pruned} {$label} timeline event(s).");
    }
}
