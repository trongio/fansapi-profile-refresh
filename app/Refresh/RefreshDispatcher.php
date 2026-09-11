<?php

namespace App\Refresh;

use App\Jobs\RefreshProfileJob;
use App\Models\Profile;
use App\Models\RefreshRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pending-work coordination.
 *
 * The durable claim is profiles.pending_run_id, taken under a row lock, so
 * repeated or concurrent scheduling cannot create work that is already
 * pending. The Redis push happens afterCommit; that avoids dispatching before
 * SQL is durable, but SQL and Redis are still two systems, so a run that was
 * claimed and never delivered is picked up later by reconcile() using the
 * SAME logical run id.
 */
class RefreshDispatcher
{
    public function __construct(private readonly RefreshLogger $log) {}

    /** Returns the new run, or null when the profile already has pending work. */
    public function enqueue(Profile $profile, string $trigger = 'schedule', ?RefreshRun $replayOf = null): ?RefreshRun
    {
        $run = DB::transaction(function () use ($profile, $trigger, $replayOf): ?RefreshRun {
            /** @var Profile $locked */
            $locked = Profile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->pending_run_id !== null) {
                return null; // already queued / running / delayed
            }

            $now = Carbon::now();
            $run = RefreshRun::create([
                'profile_id' => $locked->id,
                'account_id' => $locked->account_id,
                'status' => RefreshRun::STATUS_QUEUED,
                'trigger' => $trigger,
                'enqueued_at' => $now,
                'deadline_at' => $now->copy()->addMinutes((int) config('fansapi.refresh.deadline_minutes')),
                'claim_token' => (string) Str::uuid(),
                'replay_of_run_id' => $replayOf?->id,
            ]);

            $locked->forceFill(['pending_run_id' => $run->id, 'terminal_failed_at' => null])->save();

            return $run;
        }, 3);

        if ($run !== null) {
            $this->push($run->load('account'));
        }

        return $run;
    }

    /** Push (or re-push) the job for an existing logical run. */
    public function push(RefreshRun $run, int $delaySeconds = 0): void
    {
        $queue = $run->account->queue;

        $job = RefreshProfileJob::dispatch($run->id)->onQueue($queue)->afterCommit();
        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }

        $run->forceFill([
            'dispatched_at' => Carbon::now(),
            'available_at' => Carbon::now()->addSeconds($delaySeconds),
        ])->save();
    }

    /**
     * Recovery for claims that were never delivered, plus deadline enforcement.
     *
     * Delayed and in-flight work is respected: a run whose available_at is in
     * the future, or that was touched inside the grace window, is left alone,
     * so reconciliation cannot turn into a retry storm.
     *
     * @return array{redispatched:int,expired:int}
     */
    public function reconcile(): array
    {
        $now = Carbon::now();
        $grace = (int) config('fansapi.refresh.reconcile_after_seconds');
        $redispatched = 0;
        $expired = 0;

        RefreshRun::query()
            ->with('account')
            ->whereIn('status', [RefreshRun::STATUS_QUEUED, RefreshRun::STATUS_RUNNING])
            ->where('updated_at', '<=', $now->copy()->subSeconds($grace))
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $now))
            ->orderBy('id')
            ->limit((int) config('fansapi.refresh.batch_size'))
            ->each(function (RefreshRun $run) use ($now, &$redispatched, &$expired): void {
                if ($run->deadline_at->lte($now)) {
                    app(RunRecorder::class)->failTerminally($run, Outcome::DEADLINE_EXCEEDED, 'logical deadline exceeded');
                    $expired++;

                    return;
                }

                $this->push($run);
                $this->log->event('refresh_redispatched', $run, ['reason' => 'abandoned_claim']);
                $redispatched++;
            });

        return ['redispatched' => $redispatched, 'expired' => $expired];
    }

    /**
     * Bounded, indexed due-selection. Only scheduling columns are read, and
     * never a snapshot, so the scan stays flat as the table grows.
     *
     * @return int number of runs enqueued
     */
    public function dispatchDue(?int $limit = null): int
    {
        $limit ??= (int) config('fansapi.refresh.batch_size');
        $now = Carbon::now();
        $enqueued = 0;

        Profile::query()
            ->select(['id', 'account_id', 'pending_run_id', 'next_refresh_at', 'terminal_failed_at'])
            ->due($now)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Profile $profile) use (&$enqueued): void {
                if ($this->enqueue($profile, 'schedule') !== null) {
                    $enqueued++;
                }
            });

        return $enqueued;
    }
}
