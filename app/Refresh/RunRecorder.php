<?php

namespace App\Refresh;

use App\Enums\RunStatus;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Models\RefreshRun;
use App\Refresh\Clients\ClientResult;
use Illuminate\Support\Carbon;

/**
 * Writes the compact history and the failure metadata.
 *
 * Failure paths never touch likes, revision, snapshot or last_success_at.
 * The whole last valid snapshot survives every failure mode.
 */
class RunRecorder
{
    public function __construct(private readonly RefreshLogger $log) {}

    public function recordAttempt(RefreshRun $run, ClientResult $result, ?int $receivedRevision = null, ?string $message = null): RefreshAttempt
    {
        $run->increment('requests_used');
        $run->refresh();

        Profile::query()->whereKey($run->profile_id)->update(['last_attempt_at' => Carbon::now()]);

        return RefreshAttempt::create([
            'refresh_run_id' => $run->id,
            'attempt_no' => $run->requests_used,
            'http_status' => $result->status,
            'category' => $result->category,
            'duration_ms' => $result->durationMs,
            'retry_after_seconds' => $result->retryAfterSeconds,
            'received_revision' => $receivedRevision,
            'message' => $message ?? $result->message,
        ]);
    }

    /**
     * Terminal failure: the run stops, the profile keeps every accepted value,
     * and the pending pointer is reconciled - but only if it still points here.
     */
    public function failTerminally(RefreshRun $run, string $category, string $message, RunStatus $status = RunStatus::Failed): void
    {
        $now = Carbon::now();

        $run->forceFill([
            'status' => $status,
            'outcome_category' => $category,
            'outcome_message' => substr($message, 0, 255),
            'completed_at' => $now,
        ])->save();

        $profile = Profile::query()->whereKey($run->profile_id)->first();
        if ($profile !== null) {
            $profile->forceFill([
                'last_failure_at' => $now,
                'last_failure_category' => $category,
                'last_failure_message' => substr($message, 0, 255),
                'consecutive_failures' => $profile->consecutive_failures + 1,
                'terminal_failed_at' => $now,
            ])->save();

            Profile::query()
                ->whereKey($profile->getKey())
                ->where('pending_run_id', $run->getKey())
                ->update(['pending_run_id' => null]);
        }

        $run->resolveReplayedOriginal();

        $this->log->event($status === RunStatus::DeadLettered ? 'dead_lettered' : 'request_failed', $run, [
            'category' => $category,
            'outcome' => $status->value,
        ]);
    }
}
