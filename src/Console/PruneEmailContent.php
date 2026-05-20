<?php

namespace STS\Postmaster\Console;

use Illuminate\Console\Command;
use STS\Postmaster\Models\EmailMessage;

/**
 * Purges stored message content (sender, recipients, bodies, attachment
 * names) from records older than the configured retention window. The
 * records themselves are kept — only the heavy, potentially sensitive
 * content columns are nulled.
 */
class PruneEmailContent extends Command
{
    protected $signature = 'postmaster:prune-content';

    protected $description = 'Purge stored email content past the configured retention window';

    public function handle(): int
    {
        $days = config('postmaster.persistence.prune_content_after_days');

        if ($days === null) {
            $this->info('No content retention window configured (persistence.prune_content_after_days); nothing to prune.');

            return self::SUCCESS;
        }

        $class = config('postmaster.persistence.model', EmailMessage::class);

        $pruned = (new $class)->newQuery()
            ->where('created_at', '<', now()->subDays((int) $days))
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

        return self::SUCCESS;
    }
}
