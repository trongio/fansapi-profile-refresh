<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:refresh-a' => 60,
        'redis:refresh-b' => 60,
        'redis:refresh-broken' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    // Bounded retention: Horizon history is a demo aid, not a data store.
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 30,
        'recent_failed' => 1440,
        'failed' => 1440,
        'monitored' => 1440,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    /*
    | Worker layout.
    |
    | timeout (30s) is the Horizon supervisor's own limit. It sits ABOVE the
    | job's own 20s $timeout and BELOW the Redis retry_after of 60s, so:
    |   job timeout 20s  <  supervisor timeout 30s  <  reservation 60s
    | A normal attempt can never have its reservation expire underneath it,
    | and a hung attempt is killed long before Redis hands it to a second
    | worker. The per-job $timeout property overrides the ordinary worker
    | timeout, which is why both numbers are stated here.
    |
    | 'memory' is the WORKER recycling threshold in MiB of PHP allocation
    | (config('horizon.memory_limit') above is the MASTER process limit).
    |
    | Capacity: one worker for account A, one for account B, and two sharing
    | the baseline FIFO queue. Only one mode's queues carry work at a time, so
    | each measured run has exactly two workers doing the work.
    */
    'defaults' => [
        'supervisor-account-a' => [
            'connection' => 'redis',
            'queue' => ['refresh-a', 'default'],
            'balance' => 'false',
            // With balancing off, Horizon starts floor((min+max)/2) workers,
            // so both bounds are pinned to fix the count exactly.
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => (int) env('HORIZON_MEMORY_LIMIT', 96),
            'tries' => 100,
            'timeout' => 30,
            'nice' => 0,
        ],

        'supervisor-account-b' => [
            'connection' => 'redis',
            'queue' => ['refresh-b'],
            'balance' => 'false',
            // With balancing off, Horizon starts floor((min+max)/2) workers,
            // so both bounds are pinned to fix the count exactly.
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => (int) env('HORIZON_MEMORY_LIMIT', 96),
            'tries' => 100,
            'timeout' => 30,
            'nice' => 0,
        ],

        // Baseline only: two workers sharing ONE FIFO queue, which is what the
        // broken-mode comparison needs. Idle whenever the fixed workload runs.
        'supervisor-baseline' => [
            'connection' => 'redis',
            'queue' => ['refresh-broken'],
            'balance' => 'false',
            // With balancing off, Horizon starts floor((min+max)/2) workers,
            // so both bounds are pinned to fix the count exactly.
            'minProcesses' => 2,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => (int) env('HORIZON_MEMORY_LIMIT', 96),
            'tries' => 1,
            'timeout' => 30,
            'nice' => 0,
        ],
    ],

    // Every environment runs the same three supervisors. Only one mode's
    // queues ever carry work, so each workload run has exactly two workers.
    'environments' => [
        'production' => [
            'supervisor-account-a' => [],
            'supervisor-account-b' => [],
            'supervisor-baseline' => [],
        ],
        'local' => [
            'supervisor-account-a' => [],
            'supervisor-account-b' => [],
            'supervisor-baseline' => [],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
