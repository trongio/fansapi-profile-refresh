<?php

namespace App\Refresh;

use App\Models\Profile;
use App\Models\RefreshRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes accepted profile data.
 *
 * One short transaction: lock the run and the profile, re-check the revision
 * under the lock, write, and mark the run terminal. No HTTP happens in here.
 */
class ProfileWriter
{
    public function commit(RefreshRun $run, NormalizedProfile $incoming, string $source): WriteResult
    {
        return DB::transaction(function () use ($run, $incoming, $source): WriteResult {
            /** @var RefreshRun $run */
            $run = RefreshRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            // Idempotency at the commit boundary: a worker that died after the
            // commit but before the ack replays into exactly this branch.
            if ($run->isTerminal()) {
                return WriteResult::alreadyCompleted($run->status);
            }

            /** @var Profile $profile */
            $profile = Profile::query()->whereKey($run->profile_id)->lockForUpdate()->firstOrFail();

            $accepted = $profile->revision;
            $received = $incoming->revision;

            if ($received !== null && $accepted !== null) {
                if ($received < $accepted) {
                    // Ordering is decided by the upstream revision, never by
                    // the order the queue happened to deliver the jobs in.
                    $this->finishRun($run, $profile, WriteResult::staleIgnored($received, $accepted), $received, null);

                    return WriteResult::staleIgnored($received, $accepted);
                }

                if ($received === $accepted) {
                    if (! $this->sameContent($profile, $incoming)) {
                        throw InvalidPayload::schema("revision {$received} was re-issued with different content");
                    }

                    $result = WriteResult::verifiedUnchanged();
                    $now = Carbon::now();
                    $profile->forceFill([
                        'last_attempt_at' => $now,
                        'last_success_at' => $now,
                        'verified_unchanged_at' => $now,
                        'verified_unchanged_count' => $profile->verified_unchanged_count + 1,
                        'consecutive_failures' => 0,
                        'last_failure_category' => null,
                        'last_failure_message' => null,
                        'next_refresh_at' => $this->nextRefreshAt($profile->likes ?? 0, $now),
                    ])->save();

                    $this->finishRun($run, $profile, $result, $received, $accepted);

                    return $result;
                }
            }

            $now = Carbon::now();
            $profile->forceFill([
                'likes' => $incoming->likes,
                'revision' => $received ?? $profile->revision,
                'snapshot' => $incoming->snapshot,
                'snapshot_source' => $source,
                'snapshot_from_cache' => $incoming->fromCache,
                'display_name' => $incoming->displayName ?? $profile->display_name,
                'upstream_id' => $profile->upstream_id ?? $incoming->upstreamId,
                'last_attempt_at' => $now,
                'last_success_at' => $now,
                'success_count' => $profile->success_count + 1,
                'consecutive_failures' => 0,
                'last_failure_category' => null,
                'last_failure_message' => null,
                'terminal_failed_at' => null,
                'next_refresh_at' => $this->nextRefreshAt($incoming->likes, $now),
            ]);

            try {
                $profile->save();
            } catch (QueryException $e) {
                // The (account_id, upstream_id) unique index refused the write:
                // this username now resolves to an id another profile owns.
                throw InvalidPayload::identity('upstream id conflicts with another profile on this account');
            }

            $result = WriteResult::applied();
            $this->finishRun($run, $profile, $result, $received, $received ?? $profile->revision);

            return $result;
        }, 3);
    }

    /**
     * Above the threshold refresh every 24h, at or below it every 72h.
     * Exactly 100000 is "at or below", so it gets 72h. All UTC.
     */
    public function nextRefreshAt(int $likes, Carbon $from): Carbon
    {
        $config = config('fansapi.schedule');
        $hours = $likes > $config['hot_threshold'] ? $config['hot_interval_hours'] : $config['cold_interval_hours'];

        return $from->copy()->utc()->addHours($hours);
    }

    private function sameContent(Profile $profile, NormalizedProfile $incoming): bool
    {
        return $profile->likes === $incoming->likes
            && json_encode($profile->snapshot) === json_encode($incoming->snapshot);
    }

    private function finishRun(RefreshRun $run, Profile $profile, WriteResult $result, ?int $received, ?int $acceptedRevision): void
    {
        $run->forceFill([
            'status' => $result->runStatus,
            'outcome_category' => $result->category,
            'outcome_message' => $result->message,
            'received_revision' => $received,
            'accepted_revision' => $acceptedRevision,
            'completed_at' => Carbon::now(),
        ])->save();

        app(DeadLetters::class)->markResolved($run);

        // Only clear the pending pointer if it still points at THIS run, so a
        // late finisher cannot wipe a newer run's pending state.
        Profile::query()
            ->whereKey($profile->getKey())
            ->where('pending_run_id', $run->getKey())
            ->update(['pending_run_id' => null]);
    }
}
