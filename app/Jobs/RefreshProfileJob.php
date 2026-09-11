<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\Admission;
use App\Refresh\Clients\ClientFactory;
use App\Refresh\Clients\ClientResult;
use App\Refresh\InvalidPayload;
use App\Refresh\Outcome;
use App\Refresh\ProfileNormalizer;
use App\Refresh\ProfileWriter;
use App\Refresh\RefreshLogger;
use App\Refresh\RunRecorder;
use App\Refresh\TerminalRefreshFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestration only: budgets, admission, one HTTP call, one write, one queue
 * outcome. The transport, the field rules and the SQL write each live in their
 * own class so this file stays readable.
 *
 * The payload is a single integer. Everything durable about the work lives in
 * the refresh_runs row, so a released job never grows and never carries state
 * that could go stale in Redis.
 */
class RefreshProfileJob implements ShouldQueue
{
    use Queueable;

    /**
     * Framework backstop only. The real limits are enforced in handle():
     * a persisted deadline and a separately counted HTTP budget. Note that
     * defining retryUntil() would make Laravel ignore $tries entirely, which
     * is why this job does not define one.
     */
    public int $tries = 100;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    /** Everything the fetch and the normalizer need; deliberately no snapshot. */
    private const PROFILE_COLUMNS = ['id', 'account_id', 'username', 'upstream_id', 'revision'];

    public function __construct(public readonly int $runId) {}

    /** Non-secret ids so a single request is findable in Horizon during the call. */
    public function tags(): array
    {
        return ['run:'.$this->runId];
    }

    public function handle(
        ClientFactory $clients,
        ProfileNormalizer $normalizer,
        ProfileWriter $writer,
        RunRecorder $recorder,
        Admission $admission,
        RefreshLogger $log,
    ): void {
        // The job never needs the stored snapshot; only the writer does, under
        // its own lock. Leaving the JSON column out keeps the old snapshot from
        // sitting in memory next to the incoming body.
        $run = RefreshRun::query()
            ->with(['account', 'profile' => fn ($q) => $q->select(self::PROFILE_COLUMNS)])
            ->find($this->runId);
        if ($run === null) {
            return; // demo data was reset underneath us
        }

        // Replay of an already committed run: no second success, no timestamp
        // change, no side effect. This is the crash-after-commit case.
        if ($run->isTerminal()) {
            $log->event('replay_ignored', $run, ['job_id' => $this->job?->uuid(), 'status' => $run->status->value]);

            return;
        }

        $run->increment('deliveries');
        $run->refresh();

        if ($run->deliveries > (int) config('fansapi.refresh.delivery_guard')) {
            $this->deadLetter($run, $recorder, Outcome::BUDGET_EXHAUSTED, 'queue delivery guard exceeded');

            return;
        }

        if ($run->deadline_at->lte(Carbon::now())) {
            $this->deadLetter($run, $recorder, Outcome::DEADLINE_EXCEEDED, 'logical refresh deadline exceeded');

            return;
        }

        // Admission is NOT an upstream request: it costs a delivery, never budget.
        if (($wait = $admission->check($run->account)) !== null) {
            $run->increment('admission_deferrals');
            $this->releaseRun($run, $wait, 'admission_deferred', $log);

            return;
        }

        // Overlap protection shared by every worker that can touch this profile.
        // SQL idempotency still holds without it; this only avoids wasted work.
        $lock = Cache::lock('fansapi:profile-lease:'.$run->profile_id, (int) config('fansapi.refresh.lease_seconds'));

        if (! $lock->get()) {
            $run->increment('admission_deferrals');
            $this->releaseRun($run, 2, 'lease_busy', $log);

            return;
        }

        try {
            if ($run->requests_used >= (int) config('fansapi.refresh.http_budget')) {
                $last = $run->attempts()->orderByDesc('attempt_no')->value('category');
                $this->deadLetter($run, $recorder, Outcome::BUDGET_EXHAUSTED, sprintf(
                    'upstream request budget exhausted after %d attempts; last category was %s',
                    $run->requests_used, $last ?? 'none',
                ));

                return;
            }

            $run->forceFill(['status' => RunStatus::Running])->save();

            $client = $clients->for($run->account);

            // HTTP happens outside every database transaction.
            $result = $client->fetch($run->profile);

            if ($result->ok()) {
                try {
                    $normalized = $normalizer->normalize($result->body, $run->profile, $client->source());
                } catch (InvalidPayload $e) {
                    $recorder->recordAttempt($run, $result, null, $e->getMessage());
                    $this->deadLetter($run, $recorder, $e->category, $e->getMessage());

                    return;
                }

                $recorder->recordAttempt($run, $result, $normalized->revision);

                try {
                    $write = $writer->commit($run, $normalized, $client->source());
                } catch (InvalidPayload $e) {
                    $this->deadLetter($run, $recorder, $e->category, $e->getMessage());

                    return;
                }

                $accepted = Profile::query()->whereKey($run->profile_id)->first(['likes', 'revision']);

                $log->event($write->category, $run->refresh(), [
                    'job_id' => $this->job?->uuid(),
                    'http_status' => $result->status,
                    'duration_ms' => $result->durationMs,
                    'received_revision' => $normalized->revision,
                    'accepted_revision' => $accepted?->revision,
                    'likes' => $accepted?->likes,
                    'from_cache' => $normalized->fromCache,
                ]);

                return;
            }

            $recorder->recordAttempt($run, $result);

            match (true) {
                $result->category === Outcome::THROTTLED => $this->handleThrottle($run, $result, $admission, $log),
                $result->retryable() => $this->releaseRun($run, $this->backoffSeconds($run), $result->category, $log),
                default => $this->deadLetter($run, $recorder, $result->category, $result->message ?? $result->category),
            };
        } finally {
            $lock->release(); // ownership-safe: only the holder can release it
        }
    }

    /**
     * A permanent failure. The accepted data is preserved and the pending
     * pointer reconciled first; then the job is handed to Laravel's own
     * failure pipeline, which writes the durable failed_jobs row that IS the
     * dead-letter queue. The Queue::failing listener links its uuid back here.
     */
    private function deadLetter(RefreshRun $run, RunRecorder $recorder, string $category, string $message): void
    {
        $recorder->failTerminally($run, $category, $message);

        $this->fail(new TerminalRefreshFailure($category, $message));
    }

    private function handleThrottle(RefreshRun $run, ClientResult $result, Admission $admission, RefreshLogger $log): void
    {
        $config = config('fansapi.backoff');

        // A valid Retry-After is respected exactly; we never retry earlier.
        $delay = $result->retryAfterSeconds
            ?? random_int($config['throttle_min_seconds'], $config['throttle_max_seconds']);

        $admission->coolDownAccount($run->account, max($delay, (int) config('fansapi.cooldown.account_seconds')));

        // The managed provider bills one quota per WORKSPACE, shared by every
        // key, so a 429 from it must slow the whole workspace down. The fixture
        // models an account-specific outage, which must not stall account B.
        if (in_array($run->account->source, ['ofapi', 'onlyfans'], true)) {
            $admission->coolDownWorkspace($run->account, (int) config('fansapi.cooldown.workspace_seconds'));
        }

        $this->releaseRun($run, $delay, Outcome::THROTTLED, $log, [
            'retry_after_seconds' => $result->retryAfterSeconds,
        ]);
    }

    private function backoffSeconds(RefreshRun $run): int
    {
        $config = config('fansapi.backoff');
        $growth = $config['base_seconds'] * (2 ** max(0, $run->requests_used - 1));

        return (int) min($config['max_seconds'], $growth) + random_int(0, $config['jitter_seconds']);
    }

    /** @param array<string,mixed> $context */
    private function releaseRun(RefreshRun $run, int $delay, string $reason, RefreshLogger $log, array $context = []): void
    {
        $run->forceFill([
            'status' => RunStatus::Queued,
            'outcome_category' => $reason,
            'available_at' => Carbon::now()->addSeconds($delay),
        ])->save();

        $log->event('retry_scheduled', $run, [
            'job_id' => $this->job?->uuid(),
            'reason' => $reason,
            'retry_delay_seconds' => $delay,
        ] + $context);

        // Release, do not sleep: the worker stays free for other profiles.
        $this->release($delay);
    }
}
