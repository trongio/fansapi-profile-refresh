<?php

use Illuminate\Support\Facades\Schedule;

/*
| Both commands are cheap, bounded and safe to run every minute:
|   - fans:dispatch-due selects at most 100 rows with an index that already
|     excludes pending and terminally failed profiles, so a stuck profile is
|     not re-queued every minute;
|   - fans:reconcile only touches runs that were claimed but never delivered
|     and are outside their grace window, so it cannot become a retry storm.
*/

Schedule::command('fans:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('fans:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
