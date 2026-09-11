<?php

namespace App\Demo;

use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\Clients\ClientFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/** DEMO ONLY - the pre-fix job, kept rerunnable. See BrokenProfileHandler. */
class BrokenRefreshJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public readonly int $runId) {}

    public function tags(): array
    {
        return ['broken', 'run:'.$this->runId];
    }

    public function handle(ClientFactory $clients): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'broken handler is demo only');

        $run = RefreshRun::query()->with('profile.account')->find($this->runId);
        if ($run === null) {
            return;
        }

        $result = $clients->for($run->profile->account)->fetch($run->profile);

        // The whole bug in three lines.
        $likes = BrokenProfileHandler::likesFrom($result->body);
        $now = Carbon::now();

        Profile::query()->whereKey($run->profile_id)->update([
            'likes' => $likes,
            'last_attempt_at' => $now,
            'last_success_at' => $now,     // every response counts as success
            'success_count' => $run->profile->success_count + 1,
            'pending_run_id' => null,
        ]);

        $run->forceFill([
            'status' => RefreshRun::STATUS_SUCCEEDED,
            'outcome_category' => 'broken_false_success',
            'outcome_message' => 'HTTP '.($result->status ?? 'none').' treated as success',
            'requests_used' => 1,
            'deliveries' => 1,
            'completed_at' => $now,
        ])->save();
    }
}
