<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use STS\Postmaster\Models\EmailMessageEvent;

/**
 * Deletes recorded timeline events older than the configured retention
 * window. Unlike content pruning, whole rows are removed — the email_messages
 * summary records they belong to are left untouched.
 */
class PruneEmailMessageEvents extends Command
{
    protected $signature = 'postmaster:prune-events';

    protected $description = 'Delete recorded email timeline events past the configured retention window';

    public function handle(): int
    {
        $days = (int) config('postmaster.persistence.prune_events_after_days');

        if ($days <= 0) {
            $this->info('Event pruning is disabled (persistence.prune_events_after_days).');

            return self::SUCCESS;
        }

        $class = config('postmaster.persistence.event_model', EmailMessageEvent::class);

        $pruned = (new $class)->newQuery()
            ->where('occurred_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$pruned} email timeline event(s).");

        return self::SUCCESS;
    }
}
