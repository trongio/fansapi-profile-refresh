<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\NormalizedProfile;
use App\Refresh\Outcome;
use App\Refresh\ProfileWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The concurrency-sensitive check, on the real SQL engine, with two
 * independent processes and connections.
 *
 * RefreshDatabase is deliberately NOT used: its wrapping transaction would
 * hide the test's rows from the second process, which is exactly the thing
 * being tested. Cleanup is explicit instead.
 *
 * Coordination is deterministic: the other process takes the row lock and
 * holds it for a fixed window, and this process starts its own commit inside
 * that window, so the ordering under test is not a timing accident.
 */
class ConcurrentCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This class does not use RefreshDatabase, so on a brand-new test
        // database nothing has migrated yet when it runs first.
        Artisan::call('migrate', ['--force' => true]);

        $this->truncateDemoTables();
    }

    protected function tearDown(): void
    {
        $this->truncateDemoTables();
        parent::tearDown();
    }

    #[Test]
    public function a_concurrent_older_revision_cannot_overwrite_a_newer_committed_one(): void
    {
        $profile = $this->profile($this->account('A'), 'a-01');
        $newer = $this->newRun($profile);   // revision 11, committed by the other process
        $older = $this->newRun($profile);   // revision 10, committed by this one

        $other = new Process(
            ['php', 'artisan', 'fans:concurrent-commit',
                '--run='.$newer->id, '--likes=121000', '--revision=11', '--hold=2'],
            base_path(),
            ['DB_DATABASE' => 'fansapi_test', 'APP_ENV' => 'testing', 'REDIS_DB' => '15', 'REDIS_CACHE_DB' => '14'],
        );
        $other->start();

        // Enter the window while the other process still holds the row lock.
        usleep(700_000);
        $startedAt = microtime(true);

        $result = app(ProfileWriter::class)->commit(
            $older,
            new NormalizedProfile(120000, 10, null, 'a-01', null, ['likes' => 120000]),
            'fixture',
        );

        $blockedFor = microtime(true) - $startedAt;
        $other->wait();

        $this->assertTrue($other->isSuccessful(), $other->getErrorOutput());
        // It really did contend: this commit could not start until the lock went.
        $this->assertGreaterThan(0.5, $blockedFor);

        $profile->refresh();
        $this->assertSame(Outcome::STALE_IGNORED, $result->category);
        $this->assertSame(121000, $profile->likes);
        $this->assertSame(11, $profile->revision);
        $this->assertSame(1, $profile->success_count);
        $this->assertSame(RefreshRun::STATUS_STALE_IGNORED, $older->fresh()->status);
    }

    private function newRun(Profile $profile): RefreshRun
    {
        return RefreshRun::create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'status' => RefreshRun::STATUS_QUEUED,
            'trigger' => 'cli',
            'enqueued_at' => now(),
            'deadline_at' => now()->addMinutes(5),
            'claim_token' => (string) Str::uuid(),
        ]);
    }

    private function truncateDemoTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['refresh_attempts', 'refresh_runs', 'profiles', 'accounts', 'failed_jobs'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
