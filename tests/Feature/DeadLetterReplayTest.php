<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\DeadLetters;
use App\Refresh\Outcome;
use App\Refresh\RefreshDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Group 5: retry bounds, terminal dead-lettering, and replay protection.
 *
 * The dead letter here is a real Laravel failed_jobs row produced by a real
 * worker, not a hand-written record.
 */
class DeadLetterReplayTest extends TestCase
{
    use RefreshDatabase;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = $this->profile($this->account('A'), 'a-01');
    }

    #[Test]
    public function the_http_budget_bounds_the_real_upstream_requests(): void
    {
        // Permanently throttled, so the only thing that can stop it is a budget.
        $this->scenario(['profiles' => ['a-01' => ['status' => 429, 'body' => '']]]);

        $run = $this->drain();

        $this->assertSame((int) config('fansapi.refresh.http_budget'), $run->requests_used);
        $this->assertSame(Outcome::BUDGET_EXHAUSTED, $run->outcome_category);
        // Deliveries are counted separately and exceed the request count,
        // because a release consumes a queue attempt without an HTTP call.
        $this->assertGreaterThan($run->requests_used, $run->deliveries);
    }

    #[Test]
    public function an_exhausted_run_becomes_a_dead_letter_with_the_data_preserved(): void
    {
        $this->scenario(['profiles' => ['a-01' => ['status' => 429, 'body' => '']]]);

        $run = $this->drain();

        $this->assertSame(RunStatus::DeadLettered, $run->status);
        $this->assertNotNull($run->failed_job_uuid);
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $run->failed_job_uuid)->count());

        $this->profile->refresh();
        $this->assertSame(120000, $this->profile->likes);
        $this->assertNull($this->profile->last_success_at);
        // Terminal failure reconciles the pending pointer.
        $this->assertNull($this->profile->pending_run_id);
        $this->assertNotNull($this->profile->terminal_failed_at);
    }

    #[Test]
    public function a_repeated_replay_request_does_not_create_duplicate_active_work(): void
    {
        $this->scenario(['profiles' => ['a-01' => ['status' => 429, 'body' => '']]]);
        $original = $this->drain();
        $dlq = app(DeadLetters::class);

        $first = $dlq->replay($original);
        $second = $dlq->replay($original->fresh());

        $this->assertNotNull($first['run']);
        $this->assertSame($first['run']->id, $second['run']->id);
        $this->assertSame('a replay is already pending', $second['reason']);
        $this->assertSame(1, RefreshRun::where('replay_of_run_id', $original->id)->count());

        // Queuing a replay does NOT resolve the original failure.
        $this->assertSame(RunStatus::DeadLettered, $original->fresh()->status);
        $this->assertNull($original->fresh()->resolved_at);
    }

    #[Test]
    public function a_replay_against_a_repaired_upstream_commits_and_resolves_the_original(): void
    {
        $this->scenario(['profiles' => ['a-01' => ['status' => 429, 'body' => '']]]);
        $original = $this->drain();

        // Fixture repaired.
        $this->scenario(['profiles' => ['a-01' => ['format' => 'nested', 'likes' => 121000, 'revision' => 11]]]);

        $replay = app(DeadLetters::class)->replay($original)['run'];
        $this->workOnce('refresh-a');

        $this->profile->refresh();
        $this->assertSame(121000, $this->profile->likes);
        $this->assertSame(11, $this->profile->revision);
        $this->assertNotNull($this->profile->last_success_at);

        $this->assertSame(RunStatus::Succeeded, $replay->fresh()->status);
        // The original stays dead-lettered for the audit trail, but is resolved.
        $this->assertSame(RunStatus::DeadLettered, $original->fresh()->status);
        $this->assertNotNull($original->fresh()->resolved_at);
    }

    /** Work the queue until the run reaches a terminal state. */
    private function drain(int $maxDeliveries = 40): RefreshRun
    {
        // Delays are irrelevant to the budget; skip them so the test is fast.
        config(['fansapi.backoff.throttle_min_seconds' => 0, 'fansapi.backoff.throttle_max_seconds' => 0]);
        config(['fansapi.cooldown.account_seconds' => 0]);

        $run = app(RefreshDispatcher::class)->enqueue($this->profile, 'cli');

        for ($i = 0; $i < $maxDeliveries && ! $run->fresh()->isTerminal(); $i++) {
            $this->workOnce('refresh-a');
        }

        return $run->fresh();
    }
}
