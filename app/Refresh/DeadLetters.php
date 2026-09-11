<?php

namespace App\Refresh;

use App\Enums\RunStatus;
use App\Models\RefreshRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The dead-letter queue is Laravel's own durable failed_jobs table, joined to
 * the refresh run that produced it. No second broker, no Redis dead queue that
 * something has to keep consuming.
 *
 * queue:retry would republish the work and DELETE the failure record. We keep
 * the record: the audit trail is the point, and the original failure stays
 * visible after a replay is queued.
 */
class DeadLetters
{
    public function __construct(
        private readonly RefreshDispatcher $dispatcher,
        private readonly RunRecorder $recorder,
    ) {}

    /** Link a failed queue job to its logical run and mark the run dead. */
    public function record(int $runId, string $jobUuid, \Throwable $e): void
    {
        $run = RefreshRun::query()->find($runId);
        if ($run === null || $run->isCommitted()) {
            return;
        }

        // The job usually recorded the reason and preserved the data already;
        // then this only has to link the failure record and mark it dead.
        if ($run->isTerminal()) {
            $run->forceFill([
                'failed_job_uuid' => $jobUuid,
                'status' => RunStatus::DeadLettered,
            ])->save();

            return;
        }

        // Anything else that reached Laravel's failure pipeline: an unexpected
        // exception, or a timeout that killed the attempt mid-flight.
        $run->forceFill(['failed_job_uuid' => $jobUuid])->save();

        $this->recorder->failTerminally(
            $run,
            $run->outcome_category ?? Outcome::BUDGET_EXHAUSTED,
            substr($e->getMessage(), 0, 255),
            RunStatus::DeadLettered,
        );
    }

    /** Paginated inspection. Bounded columns only - no snapshots in the list. */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return RefreshRun::query()
            ->with(['profile:id,username,likes,revision,last_success_at', 'account:id,key,label'])
            ->whereIn('status', RunStatus::deadLetterable())
            ->orderByDesc('completed_at')
            ->paginate($perPage);
    }

    /** The raw framework record, for the detail view. */
    public function failedJob(?string $uuid): ?object
    {
        if ($uuid === null) {
            return null;
        }

        return DB::table('failed_jobs')->where('uuid', $uuid)->first(['uuid', 'queue', 'failed_at', 'exception']);
    }

    /**
     * Replay creates a NEW linked run through the ordinary dispatch and
     * admission path. The original stays dead-lettered until the replay
     * actually finishes, and a second replay request while one is already
     * pending creates nothing.
     *
     * @return array{run: ?RefreshRun, reason: ?string}
     */
    public function replay(RefreshRun $original): array
    {
        if (! in_array($original->status, RunStatus::deadLetterable(), true)) {
            return ['run' => null, 'reason' => 'run did not fail terminally'];
        }

        $existing = RefreshRun::query()
            ->where('replay_of_run_id', $original->id)
            ->whereNotIn('status', RunStatus::terminal())
            ->first();

        if ($existing !== null) {
            return ['run' => $existing, 'reason' => 'a replay is already pending'];
        }

        $profile = $original->profile;
        $profile->forceFill(['terminal_failed_at' => null])->save();

        $run = $this->dispatcher->enqueue($profile, 'replay', $original);

        return ['run' => $run, 'reason' => $run === null ? 'profile already has pending work' : null];
    }
}
