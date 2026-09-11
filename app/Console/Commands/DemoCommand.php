<?php

namespace App\Console\Commands;

use App\Demo\BaselineDispatcher;
use App\Demo\DemoData;
use App\Demo\FixtureScenario;
use App\Demo\Workload;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\DeadLetters;
use App\Refresh\QueueStats;
use App\Refresh\RefreshDispatcher;
use Illuminate\Console\Command;

/**
 * The single entry point for the interview.
 *
 *   php artisan fans:demo seed                 reset + seed ONLY demo data
 *   php artisan fans:demo state                show what is actually stored
 *   php artisan fans:demo broken               rerun the pre-fix regression
 *   php artisan fans:demo fixed                run the same four cases, fixed
 *   php artisan fans:demo workload --mode=...  the A/B queue workload
 *   php artisan fans:demo dlq                  paginated dead-letter list
 *   php artisan fans:demo replay --run=ID      replay one dead letter
 *   php artisan fans:demo live                 EXPLICIT live provider fetch
 */
class DemoCommand extends Command
{
    protected $signature = 'fans:demo
        {action : seed|state|broken|fixed|workload|dlq|dlq-demo|replay|live}
        {--mode=fixed : workload mode (broken|fixed)}
        {--run= : refresh run id for replay}
        {--wait=90 : seconds to wait for background workers}
        {--page=1 : page for dlq}
        {--json= : write the machine-readable result to this path}';

    protected $description = 'FansAPI demo: seed, reproduce, fix, measure, replay.';

    public function __construct(
        private readonly RefreshDispatcher $dispatcher,
        private readonly BaselineDispatcher $baseline,
    ) {
        parent::__construct();
    }

    public function handle(
        DemoData $data,
        FixtureScenario $fixtures,
        DeadLetters $dlq,
        QueueStats $stats,
        Workload $workload,
    ): int {
        return match ($this->argument('action')) {
            'seed' => $this->seed($data),
            'state' => $this->state($stats),
            'broken' => $this->cases($data, $fixtures, 'broken'),
            'fixed' => $this->cases($data, $fixtures, 'fixed'),
            'workload' => $this->workload($workload),
            'dlq' => $this->dlq($dlq),
            'dlq-demo' => $this->dlqDemo($data, $fixtures, $dlq),
            'replay' => $this->replay($dlq),
            'live' => $this->live(),
            default => $this->fail('unknown action'),
        };
    }

    private function seed(DemoData $data): int
    {
        $data->reset();
        $data->seed();
        $this->info(sprintf(
            'Seeded %d profiles across %d accounts at likes=%d revision=%d.',
            Profile::count(), Account::count(), DemoData::SEED_LIKES, DemoData::SEED_REVISION,
        ));

        return self::SUCCESS;
    }

    private function state(QueueStats $stats): int
    {
        $this->line('<info>Mode:</info> '.strtoupper((string) config('fansapi.source')).'   (accounts carry their own source)');

        $this->table(
            ['account', 'profile', 'likes', 'revision', 'last success (UTC)', 'last failure', 'pending', 'next refresh'],
            Profile::with('account:id,key')->orderBy('id')->get()->map(fn (Profile $p) => [
                $p->account->key,
                $p->username,
                $p->likes ?? '-',
                $p->revision ?? '-',
                $p->last_success_at?->toDateTimeString() ?? '-',
                $p->last_failure_category ?? '-',
                $p->pending_run_id ? 'yes' : 'no',
                $p->next_refresh_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        $this->table(
            ['account', 'ready', 'delayed', 'reserved', 'valid refreshes', 'retries', 'dead', 'oldest wait (s)', 'latest'],
            Account::orderBy('key')->get()->map(function (Account $a) use ($stats) {
                $row = $stats->accountRow($a);

                return [$row['account'], $row['ready'], $row['delayed'], $row['reserved'], $row['valid_refreshes'],
                    $row['retries_scheduled'], $row['dead_letters'], $row['oldest_waiting_seconds'] ?? '-', $row['latest_outcome'] ?? '-'];
            })->all(),
        );

        return self::SUCCESS;
    }

    /**
     * The four incident cases, run through real background workers.
     * Exit status is nonzero in broken mode: the point of that run is that it
     * FAILS the expectation, and the transcript should show it.
     */
    private function cases(DemoData $data, FixtureScenario $fixtures, string $mode): int
    {
        $data->reset();
        $data->seed();

        if (! $fixtures->waitUntilReady()) {
            $this->error('fixture upstream is not reachable');

            return self::FAILURE;
        }
        $fixtures->push(FixtureScenario::incidentCases());

        $profiles = Profile::whereIn('username', DemoData::CASE_PROFILES)->orderBy('username')->get();

        foreach ($profiles as $profile) {
            $mode === 'broken'
                ? $this->baseline->enqueue($profile)
                : $this->dispatcher->enqueue($profile, 'cli');
        }

        $this->waitForSettled((int) $this->option('wait'), $profiles->pluck('id')->all());

        $rows = [];
        $regressions = 0;
        foreach ($profiles->fresh() as $profile) {
            $expected = match ($profile->username) {
                'case-old-format' => ['likes' => 120000, 'revision' => 10, 'success' => true],
                'case-new-format' => ['likes' => 121000, 'revision' => 11, 'success' => true],
                default => ['likes' => DemoData::SEED_LIKES, 'revision' => DemoData::SEED_REVISION, 'success' => false],
            };

            $ok = $profile->likes === $expected['likes'] && $profile->revision === $expected['revision'];
            $regressions += $ok ? 0 : 1;
            $verdict = match (true) {
                $ok => 'OK',
                $profile->likes === 0 => 'ZEROED',
                default => 'NOT APPLIED',
            };

            $rows[] = [
                $profile->username,
                $profile->likes ?? 'null',
                $profile->revision ?? 'null',
                $profile->last_success_at?->toDateTimeString() ?? '-',
                $profile->last_failure_category ?? '-',
                $expected['likes'].' / '.$expected['revision'],
                $verdict,
            ];
        }

        $this->newLine();
        $this->line("<comment>Mode: {$mode}</comment>  (expectation: a response we cannot validate must preserve the last valid snapshot)");
        $this->table(['case', 'stored likes', 'stored revision', 'last success', 'last failure', 'expected likes/rev', 'verdict'], $rows);

        if ($mode === 'broken') {
            $zeroed = count(array_filter($rows, fn ($r) => $r[6] === 'ZEROED'));
            $this->error(sprintf(
                '%d of %d cases did not end with the expected accepted data (%d were overwritten with zero), and all %d were recorded as successful refreshes.',
                $regressions, count($rows), $zeroed, count($rows),
            ));
            $this->line('This is the reproduction. Exit status 1 is the expected result here.');

            return self::FAILURE;
        }

        if ($regressions > 0) {
            $this->error("{$regressions} case(s) did not match the expectation.");

            return self::FAILURE;
        }

        $this->info('All four cases preserved or advanced data correctly.');

        return self::SUCCESS;
    }

    private function workload(Workload $workload): int
    {
        $mode = $this->option('mode');
        if (! in_array($mode, ['broken', 'fixed'], true)) {
            $this->error('--mode must be broken or fixed');

            return self::FAILURE;
        }

        $evidence = $workload->run($mode, fn (string $line) => $this->line('  '.$line), (int) $this->option('wait'));

        $this->newLine();
        $this->table(
            ['account', 'runs', 'valid refreshes', 'false successes', 'upstream reqs', 'reqs/valid refresh', 'retries', 'dead', 'peak oldest wait (s)', 'final oldest wait (s)'],
            collect($evidence['accounts'])->map(fn (array $r, string $key) => [
                $key, $r['runs'], $r['valid_refreshes'], $r['false_successes'], $r['upstream_requests'],
                $r['attempts_per_valid_refresh'] ?? 'undefined', $r['retries_scheduled'], $r['dead_letters'],
                $r['peak_oldest_waiting_seconds'] ?? '-', $r['final_oldest_waiting_seconds'] ?? '-',
            ])->values()->all(),
        );

        $this->writeJson($evidence);

        return self::SUCCESS;
    }

    private function dlq(DeadLetters $dlq): int
    {
        $page = $dlq->paginate((int) config('fansapi.ui.per_page'));

        $this->table(
            ['run', 'account', 'profile', 'category', 'stored likes', 'last success', 'failed job'],
            collect($page->items())->map(fn (RefreshRun $r) => [
                $r->id, $r->account->key, $r->profile->username, $r->outcome_category,
                $r->profile->likes, $r->profile->last_success_at?->toDateTimeString() ?? '-',
                $r->failed_job_uuid ? substr($r->failed_job_uuid, 0, 8) : '-',
            ])->all(),
        );
        $this->line(sprintf('page %d of %d, %d dead letters', $page->currentPage(), max(1, $page->lastPage()), $page->total()));

        return self::SUCCESS;
    }

    /**
     * failure -> exhausted -> dead letter -> fixture repaired -> replay -> valid data.
     * No manual SQL anywhere in it.
     */
    private function dlqDemo(DemoData $data, FixtureScenario $fixtures, DeadLetters $dlq): int
    {
        $data->reset();
        $data->seed();

        if (! $fixtures->waitUntilReady()) {
            $this->error('fixture upstream is not reachable');

            return self::FAILURE;
        }

        $profile = Profile::where('username', 'a-01')->firstOrFail();

        $this->line('1. upstream is permanently throttled for a-01');
        $fixtures->push(['profiles' => ['a-01' => ['status' => 429, 'body' => '']]]);
        $this->dispatcher->enqueue($profile, 'cli');
        $this->waitForSettled((int) $this->option('wait'), [$profile->id]);

        $original = RefreshRun::where('profile_id', $profile->id)->orderByDesc('id')->firstOrFail();
        $this->table(['field', 'value'], [
            ['run', $original->id],
            ['status', $original->status->value],
            ['reason', $original->outcome_message],
            ['upstream requests', $original->requests_used],
            ['queue deliveries', $original->deliveries],
            ['failed_jobs uuid', $original->failed_job_uuid ?? '-'],
            ['preserved likes', $profile->fresh()->likes],
            ['last success', $profile->fresh()->last_success_at?->toDateTimeString() ?? 'never'],
        ]);

        $this->line('2. fixture repaired: a-01 now serves the new nested format at revision 11');
        $fixtures->push(['profiles' => ['a-01' => ['format' => 'nested', 'likes' => 121000, 'revision' => 11]]]);

        $this->line('3. replaying through the ordinary dispatch path');
        $first = $dlq->replay($original);
        $second = $dlq->replay($original->fresh());
        $this->line('   concurrent replay request returned: '.($second['reason'] ?? 'a second run'));

        $this->waitForSettled((int) $this->option('wait'), [$profile->id]);

        $profile->refresh();
        $original->refresh();
        $replay = $first['run']->fresh();

        $this->table(['field', 'value'], [
            ['replay run', $replay->id],
            ['replay status', $replay->status->value],
            ['stored likes', $profile->likes],
            ['stored revision', $profile->revision],
            ['last success', $profile->last_success_at?->toDateTimeString() ?? 'never'],
            ['original run status', $original->status->value.' (kept for the audit trail)'],
            ['original resolved at', $original->resolved_at?->toDateTimeString() ?? 'not resolved'],
            ['duplicate active replays', RefreshRun::where('replay_of_run_id', $original->id)->count()],
        ]);

        $ok = $profile->likes === 121000 && $profile->revision === 11 && $original->resolved_at !== null;
        $ok ? $this->info('Dead letter replayed and resolved; data is valid.') : $this->error('Replay did not produce valid data.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function replay(DeadLetters $dlq): int
    {
        $run = RefreshRun::find((int) $this->option('run'));
        if ($run === null) {
            $this->error('--run=ID is required and must exist');

            return self::FAILURE;
        }

        $result = $dlq->replay($run);
        if ($result['run'] === null) {
            $this->warn('No replay created: '.$result['reason']);

            return self::FAILURE;
        }

        $this->info(sprintf('Replay run %d queued for %s (original run %d stays dead-lettered until this finishes).',
            $result['run']->id, $run->profile->username, $run->id));

        return self::SUCCESS;
    }

    /** Deliberately separate: no other action ever touches the live provider. */
    private function live(): int
    {
        $profile = Profile::whereHas('account', fn ($q) => $q->where('source', 'ofapi'))->first();
        if ($profile === null) {
            $this->error('No live profile seeded. Run: php artisan fans:demo seed');

            return self::FAILURE;
        }

        if (blank(config('fansapi.ofapi.token'))) {
            $this->error('OFAPI_TOKEN is not set in .env');

            return self::FAILURE;
        }

        $profile->forceFill(['pending_run_id' => null, 'terminal_failed_at' => null])->save();
        $run = $this->dispatcher->enqueue($profile, 'cli');
        if ($run === null) {
            $this->error('profile already has pending work');

            return self::FAILURE;
        }

        $this->line("Queued live run {$run->id} for {$profile->username} (real background worker).");
        $this->waitForSettled((int) $this->option('wait'), [$profile->id]);

        $run->refresh();
        $profile->refresh();

        $this->table(['field', 'value'], [
            ['run status', $run->status->value],
            ['outcome', $run->outcome_category ?? '-'],
            ['upstream requests', $run->requests_used],
            ['profile id (upstream)', $profile->upstream_id ?? '-'],
            ['likes (favoritedCount)', $profile->likes ?? '-'],
            ['favoritesCount (outgoing, retained)', data_get($profile->snapshot, 'favoritesCount', '-')],
            ['subscriber count', 'not exposed by this endpoint (null)'],
            ['upstream revision', 'not exposed by this endpoint (null)'],
            ['served from provider cache', $profile->snapshot_from_cache ? 'yes' : 'no'],
            ['last success (UTC)', $profile->last_success_at?->toDateTimeString() ?? '-'],
            ['next refresh (UTC)', $profile->next_refresh_at?->toDateTimeString() ?? '-'],
        ]);

        $this->writeJson([
            'run' => ['id' => $run->id, 'status' => $run->status->value] + $run->only(['outcome_category', 'requests_used']),
            'profile' => $profile->only(['username', 'upstream_id', 'likes', 'revision', 'last_success_at', 'next_refresh_at']),
        ]);

        return $run->isCommitted() ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<int> $profileIds */
    private function waitForSettled(int $seconds, array $profileIds): void
    {
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            $pending = Profile::whereIn('id', $profileIds)->whereNotNull('pending_run_id')->count();
            if ($pending === 0) {
                return;
            }
            usleep(500_000);
        }

        $this->warn('Timed out waiting for background workers; showing current state.');
    }

    private function writeJson(array $payload): void
    {
        $path = $this->option('json');
        if ($path === null) {
            return;
        }

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("wrote {$path}");
    }
}
