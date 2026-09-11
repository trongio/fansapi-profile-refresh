<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\RefreshDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Group 4: due selection, repeated and overlapping scheduling, terminal
 * failures staying out of the schedule, and recovery of an abandoned dispatch
 * using the SAME logical run id.
 */
class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    private RefreshDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = app(RefreshDispatcher::class);
    }

    #[Test]
    public function only_due_profiles_are_selected_and_the_batch_is_bounded(): void
    {
        $account = $this->account();
        $this->profile($account, 'due-1', ['next_refresh_at' => now()->subHour()]);
        $this->profile($account, 'due-2', ['next_refresh_at' => null]);
        $this->profile($account, 'not-due', ['next_refresh_at' => now()->addDay()]);

        $this->assertSame(2, $this->dispatcher->dispatchDue());
        $this->assertSame(2, RefreshRun::count());

        // The bound is honoured even when more rows qualify.
        $this->profile($account, 'due-3', ['next_refresh_at' => now()->subHour()]);
        $this->profile($account, 'due-4', ['next_refresh_at' => now()->subHour()]);
        $this->assertSame(1, $this->dispatcher->dispatchDue(limit: 1));
    }

    /**
     * Name is literal: these calls are sequential, and they prove the durable
     * claim, not simultaneous execution. The two-connection race is covered by
     * ConcurrentCommitTest.
     */
    #[Test]
    public function repeated_scheduling_does_not_create_work_that_is_already_pending(): void
    {
        $profile = $this->profile($this->account(), 'a-01', ['next_refresh_at' => now()->subHour()]);

        $this->assertSame(1, $this->dispatcher->dispatchDue());
        $this->assertSame(0, $this->dispatcher->dispatchDue());
        $this->assertNull($this->dispatcher->enqueue($profile->fresh(), 'ui'));
        $this->assertSame(1, RefreshRun::count());
    }

    #[Test]
    public function an_unresolved_terminal_failure_is_not_rescheduled_every_minute(): void
    {
        $profile = $this->profile($this->account(), 'a-01', ['next_refresh_at' => now()->subHour()]);
        $profile->forceFill(['terminal_failed_at' => now(), 'last_failure_category' => 'profile_missing'])->save();

        $this->assertSame(0, $this->dispatcher->dispatchDue());
        $this->assertSame(0, RefreshRun::count());
    }

    #[Test]
    public function an_abandoned_claim_is_recovered_under_the_same_logical_run_id(): void
    {
        $profile = $this->profile($this->account(), 'a-01');
        $run = $this->dispatcher->enqueue($profile, 'cli');

        // Simulate a claim whose Redis push never happened, aged past the grace.
        $run->forceFill(['dispatched_at' => null, 'available_at' => null])->save();
        RefreshRun::whereKey($run->id)->update(['updated_at' => now()->subMinutes(10)]);

        $result = $this->dispatcher->reconcile();

        $this->assertSame(1, $result['redispatched']);
        $this->assertSame(0, $result['expired']);
        // Same logical run: no duplicate work was created.
        $this->assertSame(1, RefreshRun::count());
        $this->assertNotNull($run->fresh()->dispatched_at);
    }

    #[Test]
    public function reconciliation_leaves_delayed_and_recently_touched_work_alone(): void
    {
        $profile = $this->profile($this->account(), 'a-01');
        $run = $this->dispatcher->enqueue($profile, 'cli');

        // Delayed into the future: a retry storm would re-push it now.
        $run->forceFill(['available_at' => now()->addMinutes(2)])->save();
        RefreshRun::whereKey($run->id)->update(['updated_at' => now()->subMinutes(10)]);

        $this->assertSame(0, $this->dispatcher->reconcile()['redispatched']);
    }

    #[Test]
    public function a_run_past_its_deadline_is_failed_rather_than_retried_forever(): void
    {
        $profile = $this->profile($this->account(), 'a-01');
        $run = $this->dispatcher->enqueue($profile, 'cli');
        $run->forceFill(['deadline_at' => Carbon::now()->subMinute()])->save();
        RefreshRun::whereKey($run->id)->update(['updated_at' => now()->subMinutes(10)]);

        $result = $this->dispatcher->reconcile();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        // The failure preserved the accepted data and released the pointer.
        $this->assertSame(120000, $profile->fresh()->likes);
        $this->assertNull($profile->fresh()->pending_run_id);
    }

    #[Test]
    public function a_successful_refresh_schedules_the_next_one_by_the_likes_threshold(): void
    {
        $account = $this->account();
        $hot = $this->profile($account, 'hot', ['likes' => 100001]);
        $cold = $this->profile($account, 'cold', ['likes' => 100000]);

        $this->scenario(['profiles' => [
            'hot' => ['format' => 'nested', 'likes' => 100001, 'revision' => 11],
            'cold' => ['format' => 'nested', 'likes' => 100000, 'revision' => 11],
        ]]);

        foreach ([$hot, $cold] as $profile) {
            $this->dispatcher->enqueue($profile, 'cli');
            $this->workOnce('refresh-a');
        }

        $this->assertEqualsWithDelta(24, $this->hoursUntilRefresh($hot), 0.1);
        $this->assertEqualsWithDelta(72, $this->hoursUntilRefresh($cold), 0.1);
    }

    private function hoursUntilRefresh(Profile $profile): float
    {
        $profile->refresh();

        return Carbon::now()->diffInHours($profile->next_refresh_at, absolute: true);
    }
}
