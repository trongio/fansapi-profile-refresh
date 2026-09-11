<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\NormalizedProfile;
use App\Refresh\ProfileWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Test support only.
 *
 * Commits a refresh run from a SEPARATE process and connection, holding the
 * profile row lock for a moment first, so ConcurrentCommitTest can prove the
 * conditional update under real contention rather than in one transaction.
 */
class ConcurrentCommitCommand extends Command
{
    protected $signature = 'fans:concurrent-commit
        {--run= : refresh run id}
        {--likes=}
        {--revision=}
        {--hold=2 : seconds to hold the row lock before committing}';

    protected $description = 'Test support: commit a refresh run from another process while holding its row lock.';

    protected $hidden = true;

    public function handle(ProfileWriter $writer): int
    {
        $run = RefreshRun::findOrFail((int) $this->option('run'));
        $likes = (int) $this->option('likes');
        $revision = (int) $this->option('revision');

        DB::transaction(function () use ($run, $writer, $likes, $revision): void {
            Profile::query()->whereKey($run->profile_id)->lockForUpdate()->firstOrFail();

            usleep((int) ((float) $this->option('hold') * 1_000_000));

            $writer->commit($run, new NormalizedProfile(
                likes: $likes,
                revision: $revision,
                upstreamId: null,
                username: $run->profile->username,
                displayName: null,
                snapshot: ['likes' => $likes],
            ), 'fixture');
        });

        $this->line("committed run {$run->id} at revision {$revision}");

        return self::SUCCESS;
    }
}
