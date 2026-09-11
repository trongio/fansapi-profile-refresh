<?php

namespace App\Console\Commands;

use App\Refresh\RefreshDispatcher;
use Illuminate\Console\Command;

/** Scheduled every minute. Re-pushes abandoned claims and expires overdue runs. */
class ReconcileRunsCommand extends Command
{
    protected $signature = 'fans:reconcile';

    protected $description = 'Recover refresh runs that were claimed but never delivered.';

    public function handle(RefreshDispatcher $dispatcher): int
    {
        $result = $dispatcher->reconcile();
        $this->info("redispatched: {$result['redispatched']}, expired: {$result['expired']}");

        return self::SUCCESS;
    }
}
