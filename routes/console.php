<?php

use App\Refresh\RefreshDispatcher;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduling.
|
| Both entries are cheap, bounded and safe to run every minute:
|   - dispatchDue() selects at most 100 rows with an index that already
|     excludes pending and terminally failed profiles, so a stuck profile is
|     not re-queued every minute;
|   - reconcile() only touches runs that were claimed but never delivered and
|     are outside their grace window, so it cannot become a retry storm.
*/

Schedule::call(fn () => app(RefreshDispatcher::class)->dispatchDue())
    ->everyMinute()
    ->name('dispatch-due-profiles')
    ->withoutOverlapping();

Schedule::call(fn () => app(RefreshDispatcher::class)->reconcile())
    ->everyMinute()
    ->name('reconcile-abandoned-runs')
    ->withoutOverlapping();

// Horizon's own metrics snapshots.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
