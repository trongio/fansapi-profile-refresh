<?php

namespace App\Demo;

use App\Enums\RunStatus;
use App\Models\Profile;
use App\Models\RefreshRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * DEMO ONLY. Creates the same durable run row the real dispatcher would, but
 * pushes the pre-fix job onto the shared baseline queue instead.
 */
class BaselineDispatcher
{
    public function enqueue(Profile $profile): RefreshRun
    {
        $now = Carbon::now();

        $run = RefreshRun::create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'status' => RunStatus::Queued,
            'trigger' => 'cli',
            'enqueued_at' => $now,
            'deadline_at' => $now->copy()->addMinutes((int) config('fansapi.refresh.deadline_minutes')),
            'claim_token' => (string) Str::uuid(),
        ]);
        $profile->forceFill(['pending_run_id' => $run->id])->save();

        BrokenRefreshJob::dispatch($run->id)->onQueue('refresh-broken');

        return $run;
    }
}
