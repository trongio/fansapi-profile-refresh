<?php

namespace App\Refresh;

use App\Enums\RunStatus;
use App\Models\Account;
use App\Models\RefreshRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * Ready / delayed / reserved are three different things and are reported
 * separately. Sizes come from Redis with three O(1) calls per queue - we never
 * scan or decode job payloads on a poll.
 *
 * Waiting age comes from SQL (refresh_runs.enqueued_at), NOT from a delayed
 * job's next-ready timestamp, so a job released four times still reports its
 * true age since it was first enqueued.
 */
class QueueStats
{
    /** @return array{ready:int,delayed:int,reserved:int} */
    public function depth(string $queue): array
    {
        $key = 'queues:'.$queue;

        return [
            'ready' => (int) Redis::llen($key),
            'delayed' => (int) Redis::zcard($key.':delayed'),
            'reserved' => (int) Redis::zcard($key.':reserved'),
        ];
    }

    /** @return array<string,mixed> one activity-panel row */
    public function accountRow(Account $account, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $depth = $this->depth($account->queue);

        $runs = RefreshRun::query()->where('account_id', $account->id);

        $pending = (clone $runs)->whereIn('status', RunStatus::active());
        $oldest = (clone $pending)->min('enqueued_at');

        $latest = (clone $runs)->orderByDesc('id')->first(['status', 'outcome_category', 'completed_at']);

        return [
            'account' => $account->key,
            'label' => $account->label,
            'queue' => $account->queue,
            'ready' => $depth['ready'],
            'delayed' => $depth['delayed'],
            'reserved' => $depth['reserved'],
            'in_progress' => (clone $runs)->where('status', RunStatus::Running)->count(),
            // Valid refreshes = committed logical runs, not completed queue jobs.
            'valid_refreshes' => (clone $runs)
                ->whereIn('status', RunStatus::committed())
                ->where('outcome_category', '!=', 'broken_false_success')
                ->count(),
            'data_updates' => (clone $runs)
                ->where('status', RunStatus::Succeeded)
                ->where('outcome_category', '!=', 'broken_false_success')
                ->count(),
            // Retries = scheduled retries (releases), i.e. deliveries beyond the first.
            'retries_scheduled' => max(0, (int) (clone $runs)->sum('deliveries') - (clone $runs)->count()),
            'upstream_requests' => (int) (clone $runs)->sum('requests_used'),
            'dead_letters' => (clone $runs)->where('status', RunStatus::DeadLettered)->count(),
            'oldest_waiting_seconds' => $oldest ? max(0, $now->getTimestamp() - Carbon::parse($oldest)->getTimestamp()) : null,
            'cooldown_seconds' => $account->cooldown_until && $account->cooldown_until->gt($now)
                ? $account->cooldown_until->getTimestamp() - $now->getTimestamp()
                : 0,
            'latest_status' => $latest?->status->value,
            'latest_outcome' => $latest?->outcome_category,
        ];
    }

    /**
     * A lease that is held while no attempt is running any more. Surfacing it
     * stops a crashed attempt from looking "in progress" forever.
     */
    public function staleLeases(): int
    {
        return RefreshRun::query()
            ->where('status', RunStatus::Running)
            ->where('updated_at', '<=', Carbon::now()->subSeconds((int) config('fansapi.refresh.lease_seconds')))
            ->count();
    }
}
