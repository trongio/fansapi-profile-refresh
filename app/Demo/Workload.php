<?php

namespace App\Demo;

use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\QueueStats;
use App\Refresh\RefreshDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The repeatable A/B workload. Both modes get IDENTICAL fixture inputs and the
 * same total capacity of two workers; only the handler and the worker layout
 * differ, so the comparison is not rigged by adding delay to one side.
 *
 *   broken : two workers sharing ONE FIFO queue, running the pre-fix handler.
 *   fixed  : one reserved worker per account, running the real handler.
 */
class Workload
{
    public const A_PROFILES = 12;

    public const B_PROFILES = 4;

    /** B is enqueued at these offsets so its work arrives into A's burst. */
    public const B_OFFSETS = [1, 4, 7, 10];

    public const OUTAGE_SECONDS = 15;

    public function __construct(
        private readonly DemoData $data,
        private readonly FixtureScenario $fixtures,
        private readonly RefreshDispatcher $dispatcher,
        private readonly QueueStats $stats,
    ) {}

    /**
     * @param  callable(string):void  $out
     * @return array<string,mixed> the evidence document
     */
    public function run(string $mode, callable $out, int $maxSeconds = 120): array
    {
        $out("mode={$mode}: resetting demo data");
        $this->data->reset();
        $this->data->seed();

        if (! $this->fixtures->waitUntilReady()) {
            throw new \RuntimeException('fixture upstream never became ready');
        }

        // Scenario clock starts only once the environment is ready.
        $startedAt = microtime(true);
        $this->fixtures->push($this->scenario($startedAt));
        $out('fixture scenario pushed; scenario clock started');

        $accountA = Account::where('key', 'A')->firstOrFail();
        $accountB = Account::where('key', 'B')->firstOrFail();

        $aProfiles = Profile::where('account_id', $accountA->id)
            ->whereIn('username', collect(range(1, self::A_PROFILES))->map(fn ($n) => sprintf('a-%02d', $n))->all())
            ->orderBy('username')->get();
        $bProfiles = Profile::where('account_id', $accountB->id)->orderBy('username')->get();

        $runIds = [];
        foreach ($aProfiles as $profile) {
            $runIds[] = $this->dispatch($mode, $profile)?->id;
        }
        $out('account A burst queued at t=0 ('.$aProfiles->count().' profiles)');

        $pendingB = $bProfiles->values();
        $samples = [];
        $deadline = $startedAt + $maxSeconds;
        $queued = 0;

        while (microtime(true) < $deadline) {
            $elapsed = microtime(true) - $startedAt;

            while ($queued < $pendingB->count() && $elapsed >= self::B_OFFSETS[$queued]) {
                $runIds[] = $this->dispatch($mode, $pendingB[$queued])?->id;
                $out(sprintf('account B profile %s queued at t=%.1fs', $pendingB[$queued]->username, $elapsed));
                $queued++;
            }

            $samples[] = $this->sample($elapsed, [$accountA, $accountB]);

            if ($queued === $pendingB->count() && $this->allSettled()) {
                break;
            }

            usleep(1_000_000);
        }

        // One last sample AFTER the queues settle, so "final" describes the
        // drained state rather than the moment just before it drained.
        $samples[] = $this->sample(microtime(true) - $startedAt, [$accountA, $accountB]);

        $out(sprintf('workload finished after %.1fs', microtime(true) - $startedAt));

        return [
            'mode' => $mode,
            'generated_at' => Carbon::now()->toIso8601String(),
            'capacity' => ['workers' => 2, 'layout' => $mode === 'fixed' ? 'one reserved worker per account' : 'two workers sharing one FIFO queue'],
            'scenario' => [
                'a_profiles' => self::A_PROFILES,
                'b_profiles' => self::B_PROFILES,
                'seed_likes' => DemoData::SEED_LIKES,
                'seed_revision' => DemoData::SEED_REVISION,
                'a_outage_seconds' => self::OUTAGE_SECONDS,
                'b_offsets_seconds' => self::B_OFFSETS,
            ],
            'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
            'accounts' => [
                'A' => $this->accountResult($accountA, $samples),
                'B' => $this->accountResult($accountB, $samples),
            ],
            'stored_data' => $this->storedData(),
            'samples' => $samples,
        ];
    }

    private function dispatch(string $mode, Profile $profile): ?RefreshRun
    {
        if ($mode === 'fixed') {
            return $this->dispatcher->enqueue($profile, 'cli');
        }

        // Baseline: same logical run row, but the pre-fix handler on a shared queue.
        $now = Carbon::now();
        $run = RefreshRun::create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'status' => RefreshRun::STATUS_QUEUED,
            'trigger' => 'cli',
            'enqueued_at' => $now,
            'deadline_at' => $now->copy()->addMinutes(5),
            'claim_token' => (string) Str::uuid(),
        ]);
        $profile->forceFill(['pending_run_id' => $run->id])->save();

        BrokenRefreshJob::dispatch($run->id)->onQueue('refresh-broken');

        return $run;
    }

    /** Identical inputs for both modes. */
    private function scenario(float $startedAt): array
    {
        return [
            'started_at' => $startedAt,
            'accounts' => [
                // A: 15s outage of 429 without Retry-After, each response 2s slow,
                // then the new nested format at revision 11.
                'A' => [
                    'outage' => ['until_seconds' => self::OUTAGE_SECONDS, 'status' => 429, 'body' => '', 'delay_ms' => 2000],
                    'response' => ['format' => 'nested', 'likes' => 121000, 'revision' => 11, 'delay_ms' => 2000],
                ],
                // B: valid old-format data at revision 10, fast, the whole time.
                'B' => [
                    'response' => ['format' => 'legacy', 'likes' => 120500, 'revision' => 10, 'delay_ms' => 50],
                ],
            ],
            'once' => [
                // One A profile gets a single empty 500 after the outage, then succeeds.
                'a-07' => [['status' => 500, 'body' => '', 'delay_ms' => 2000, 'after_seconds' => self::OUTAGE_SECONDS]],
            ],
        ];
    }

    /** @param array<Account> $accounts */
    private function sample(float $elapsed, array $accounts): array
    {
        $row = ['t' => round($elapsed, 1)];

        foreach ($accounts as $account) {
            $stats = $this->stats->accountRow($account);
            $row[$account->key] = [
                'ready' => $stats['ready'],
                'delayed' => $stats['delayed'],
                'reserved' => $stats['reserved'],
                'valid_refreshes' => $stats['valid_refreshes'],
                'oldest_waiting_seconds' => $stats['oldest_waiting_seconds'],
            ];
        }

        return $row;
    }

    private function allSettled(): bool
    {
        return ! RefreshRun::query()->whereIn('status', [RefreshRun::STATUS_QUEUED, RefreshRun::STATUS_RUNNING])->exists();
    }

    /** @param array<int,array<string,mixed>> $samples */
    private function accountResult(Account $account, array $samples): array
    {
        $runs = RefreshRun::query()->where('account_id', $account->id);
        // A completed queue job is NOT a valid refresh. The baseline handler
        // marks everything succeeded, so its runs are counted separately.
        $valid = (clone $runs)
            ->whereIn('status', [RefreshRun::STATUS_SUCCEEDED, RefreshRun::STATUS_VERIFIED_UNCHANGED])
            ->where('outcome_category', '!=', 'broken_false_success')
            ->count();
        $requests = (int) (clone $runs)->sum('requests_used');

        $ages = collect($samples)->pluck($account->key.'.oldest_waiting_seconds');

        return [
            'queue' => $account->queue,
            'runs' => (clone $runs)->count(),
            // Baseline "successes" are counted separately: they are not valid refreshes.
            'valid_refreshes' => $valid,
            'false_successes' => (clone $runs)->where('outcome_category', 'broken_false_success')->count(),
            'upstream_requests' => $requests,
            'attempts_per_valid_refresh' => $valid > 0 ? round($requests / $valid, 2) : null,
            'retries_scheduled' => max(0, (int) (clone $runs)->sum('deliveries') - (clone $runs)->count()),
            'dead_letters' => (clone $runs)->where('status', RefreshRun::STATUS_DEAD_LETTERED)->count(),
            'peak_oldest_waiting_seconds' => $ages->filter(fn ($v) => $v !== null)->max(),
            // null here means "no pending work left", not "age zero".
            'final_oldest_waiting_seconds' => $ages->last(),
            'final_queue_depth' => $this->stats->depth($account->queue),
            'outcomes' => (clone $runs)->select('outcome_category', DB::raw('count(*) as n'))
                ->groupBy('outcome_category')->pluck('n', 'outcome_category')->all(),
        ];
    }

    private function storedData(): array
    {
        return Profile::query()
            ->with('account:id,key')
            ->orderBy('id')
            ->get(['id', 'account_id', 'username', 'likes', 'revision', 'last_success_at', 'last_failure_category', 'success_count', 'pending_run_id'])
            ->map(fn (Profile $p) => [
                'account' => $p->account->key,
                'username' => $p->username,
                'likes' => $p->likes,
                'revision' => $p->revision,
                'last_success_at' => $p->last_success_at?->toIso8601String(),
                'last_failure_category' => $p->last_failure_category,
                'success_count' => $p->success_count,
                'pending' => $p->pending_run_id !== null,
            ])->all();
    }
}
