<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\InvalidPayload;
use App\Refresh\NormalizedProfile;
use App\Refresh\Outcome;
use App\Refresh\ProfileWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Group 3: uniqueness, revision ordering, replay after commit, and the
 * verified_unchanged path.
 *
 * These run against real MySQL because the behaviour under test IS the SQL
 * behaviour: a unique index, a row lock and a conditional update.
 */
class DataProtectionTest extends TestCase
{
    use RefreshDatabase;

    private ProfileWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = app(ProfileWriter::class);
    }

    #[Test]
    public function profile_identity_is_unique_in_sql(): void
    {
        $account = $this->account();
        $this->profile($account, 'madison420ivy', ['upstream_id' => '5140520']);

        $this->expectException(QueryException::class);
        $this->profile($account, 'madison420ivy');
    }

    #[Test]
    public function the_same_username_can_exist_on_a_different_account(): void
    {
        $this->profile($this->account('A'), 'madison420ivy');
        $second = $this->profile($this->account('B'), 'madison420ivy');

        $this->assertSame(2, Profile::count());
        $this->assertNotNull($second->id);
    }

    #[Test]
    public function a_late_older_revision_cannot_overwrite_a_newer_one(): void
    {
        $profile = $this->profile($this->account(), 'a-01');

        // Revision 11 commits first.
        $this->writer->commit($this->newRun($profile), $this->incoming(121000, 11, 'a-01'), 'fixture');
        // Revision 10 arrives afterwards.
        $result = $this->writer->commit($this->newRun($profile), $this->incoming(120000, 10, 'a-01'), 'fixture');

        $profile->refresh();
        $this->assertSame(Outcome::STALE_IGNORED, $result->category);
        $this->assertSame(121000, $profile->likes);
        $this->assertSame(11, $profile->revision);
        // A stale read is not a successful refresh, so the counter stays at one.
        $this->assertSame(1, $profile->success_count);
    }

    #[Test]
    public function replaying_a_committed_run_changes_nothing(): void
    {
        $profile = $this->profile($this->account(), 'a-01');
        $run = $this->newRun($profile);

        $this->writer->commit($run, $this->incoming(121000, 11, 'a-01'), 'fixture');
        $profile->refresh();
        $firstSuccessAt = $profile->last_success_at;

        // Same logical run id delivered again: the worker died before the ack.
        $second = $this->writer->commit($run->fresh(), $this->incoming(121000, 11, 'a-01'), 'fixture');

        $profile->refresh();
        $this->assertSame('already_completed', $second->category);
        $this->assertSame(1, $profile->success_count);
        $this->assertEquals($firstSuccessAt, $profile->last_success_at);
    }

    #[Test]
    public function an_equal_revision_with_identical_content_is_recorded_as_verified_unchanged(): void
    {
        $profile = $this->profile($this->account(), 'a-01');
        $this->writer->commit($this->newRun($profile), $this->incoming(121000, 11, 'a-01'), 'fixture');

        // A separately scheduled read that confirms the same data.
        $result = $this->writer->commit($this->newRun($profile->fresh()), $this->incoming(121000, 11, 'a-01'), 'fixture');

        $profile->refresh();
        $this->assertSame(Outcome::VERIFIED_UNCHANGED, $result->category);
        $this->assertSame(1, $profile->verified_unchanged_count);
        // Counted separately from an applied data update.
        $this->assertSame(1, $profile->success_count);
    }

    #[Test]
    public function an_equal_revision_with_different_content_is_rejected(): void
    {
        $profile = $this->profile($this->account(), 'a-01');
        $this->writer->commit($this->newRun($profile), $this->incoming(121000, 11, 'a-01'), 'fixture');

        $this->expectException(InvalidPayload::class);
        $this->writer->commit($this->newRun($profile->fresh()), $this->incoming(999, 11, 'a-01'), 'fixture');
    }

    #[Test]
    public function a_late_run_cannot_clear_a_newer_runs_pending_pointer(): void
    {
        $profile = $this->profile($this->account(), 'a-01');
        $old = $this->newRun($profile);
        $new = $this->newRun($profile);

        // The newer run owns the pending pointer.
        $profile->forceFill(['pending_run_id' => $new->id])->save();

        $this->writer->commit($old, $this->incoming(121000, 11, 'a-01'), 'fixture');

        $this->assertSame($new->id, $profile->fresh()->pending_run_id);
        $this->assertSame(RunStatus::Queued, $new->fresh()->status);
    }

    #[Test]
    public function the_refresh_interval_switches_at_exactly_one_hundred_thousand(): void
    {
        $from = Carbon::parse('2026-01-01 00:00:00', 'UTC');

        // Strictly above the threshold: 24 hours.
        $this->assertSame(24, (int) $from->diffInHours($this->writer->nextRefreshAt(100001, $from)));
        // Exactly at the threshold counts as "at or below": 72 hours.
        $this->assertSame(72, (int) $from->diffInHours($this->writer->nextRefreshAt(100000, $from)));
        $this->assertSame(72, (int) $from->diffInHours($this->writer->nextRefreshAt(99999, $from)));
    }

    private function newRun(Profile $profile): RefreshRun
    {
        return RefreshRun::create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'status' => RunStatus::Queued,
            'trigger' => 'cli',
            'enqueued_at' => now(),
            'deadline_at' => now()->addMinutes(5),
            'claim_token' => (string) Str::uuid(),
        ]);
    }

    private function incoming(int $likes, int $revision, string $username): NormalizedProfile
    {
        return new NormalizedProfile($likes, $revision, null, $username, $username, ['likes' => $likes]);
    }
}
