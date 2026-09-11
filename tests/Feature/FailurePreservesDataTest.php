<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\Outcome;
use App\Refresh\RefreshDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Group 2: every failure mode preserves the whole last valid snapshot and
 * never advances the last successful refresh.
 *
 * These go through a real Redis reservation and the real fixture HTTP server,
 * so the streaming read, the release and the delivery counting are all real.
 */
class FailurePreservesDataTest extends TestCase
{
    use RefreshDatabase;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = $this->profile($this->account('A'), 'a-01');
    }

    #[Test]
    public function a_429_without_retry_after_releases_the_job_and_keeps_the_data(): void
    {
        $this->scenario(['profiles' => ['a-01' => ['status' => 429, 'body' => '']]]);

        $run = $this->refreshOnce();

        $this->assertSame(Outcome::THROTTLED, $run->attempts()->first()->category);
        $this->assertNull($run->attempts()->first()->retry_after_seconds);
        $this->assertSame(RefreshRun::STATUS_QUEUED, $run->status); // released, not failed
        $this->assertPreserved();
    }

    #[Test]
    public function a_valid_retry_after_header_is_respected_exactly(): void
    {
        $this->scenario(['profiles' => ['a-01' => ['status' => 429, 'body' => '', 'retry_after' => 17]]]);

        $run = $this->refreshOnce();

        $this->assertSame(17, $run->attempts()->first()->retry_after_seconds);
        // We never retry earlier than the upstream asked.
        $this->assertGreaterThanOrEqual(16, $run->available_at->diffInSeconds(now(), absolute: true));
        $this->assertPreserved();
    }

    #[Test]
    public function an_empty_500_is_retried_and_keeps_the_data(): void
    {
        $this->scenario(['profiles' => ['a-01' => ['status' => 500, 'body' => '']]]);

        $run = $this->refreshOnce();

        $attempt = $run->attempts()->first();
        $this->assertSame(Outcome::SERVER_ERROR, $attempt->category);
        $this->assertSame('empty body', $attempt->message);
        $this->assertPreserved();
    }

    #[Test]
    public function a_malformed_body_is_a_schema_failure_and_terminal(): void
    {
        $this->scenario(['profiles' => ['a-01' => ['raw_body' => '{"likes": 121000']]]);

        $run = $this->refreshOnce();

        $this->assertSame(Outcome::SCHEMA_FAILURE, $run->outcome_category);
        // A payload we cannot validate is quarantined for inspection, not retried.
        $this->assertSame(RefreshRun::STATUS_DEAD_LETTERED, $run->status);
        $this->assertNotNull($run->failed_job_uuid);
        $this->assertPreserved();
    }

    #[Test]
    public function likes_missing_from_a_valid_200_is_a_schema_failure_not_a_zero(): void
    {
        $this->scenario(['profiles' => ['a-01' => ['format' => 'missing_likes', 'revision' => 11]]]);

        $run = $this->refreshOnce();

        $this->assertSame(Outcome::SCHEMA_FAILURE, $run->outcome_category);
        $this->assertPreserved();
    }

    #[Test]
    public function a_404_is_a_terminal_missing_profile_and_a_403_is_not(): void
    {
        $this->scenario(['profiles' => ['a-01' => ['status' => 404, 'body' => '']]]);
        $this->assertSame(Outcome::PROFILE_MISSING, $this->refreshOnce()->outcome_category);
        $this->assertPreserved();

        $this->profile->forceFill(['pending_run_id' => null, 'terminal_failed_at' => null])->save();
        $this->scenario(['profiles' => ['a-01' => ['status' => 403, 'body' => '']]]);
        // 403 is recorded as its own category; it is never read as "deleted".
        $this->assertSame(Outcome::FORBIDDEN, $this->refreshOnce()->outcome_category);
        $this->assertPreserved();
    }

    #[Test]
    public function a_body_above_the_cap_is_rejected_while_it_is_still_arriving(): void
    {
        // 1 MiB cap; this body is deliberately larger and otherwise valid.
        $this->scenario(['profiles' => ['a-01' => ['padding_bytes' => 1_200_000, 'revision' => 11, 'likes' => 121000]]]);

        $run = $this->refreshOnce();

        $this->assertSame(Outcome::BODY_TOO_LARGE, $run->outcome_category);
        $this->assertPreserved();
    }

    private function refreshOnce(): RefreshRun
    {
        $run = app(RefreshDispatcher::class)->enqueue($this->profile, 'cli');
        $this->workOnce('refresh-a');

        return $run->fresh();
    }

    /** Nothing about the accepted snapshot may move on a failure path. */
    private function assertPreserved(): void
    {
        $this->profile->refresh();

        $this->assertSame(120000, $this->profile->likes);
        $this->assertSame(9, $this->profile->revision);
        $this->assertSame(['likes' => 120000], $this->profile->snapshot);
        $this->assertNull($this->profile->last_success_at);
        $this->assertSame(0, $this->profile->success_count);
        $this->assertNotNull($this->profile->last_attempt_at);
    }
}
