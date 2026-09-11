<?php

namespace App\Console\Commands;

use App\Refresh\RefreshDispatcher;
use Illuminate\Console\Command;

/** Scheduled every minute. Bounded, indexed, and skips anything already pending. */
class DispatchDueCommand extends Command
{
    protected $signature = 'fans:dispatch-due {--limit= : override the batch size}';

    protected $description = 'Enqueue refreshes for profiles whose next_refresh_at has passed.';

    public function handle(RefreshDispatcher $dispatcher): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $this->info('enqueued: '.$dispatcher->dispatchDue($limit));

        return self::SUCCESS;
    }
}
