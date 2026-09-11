<?php

namespace App\Refresh;

use App\Models\RefreshRun;
use Illuminate\Support\Facades\Log;

/**
 * One structured line per lifecycle event, always carrying the ids that let
 * you follow a single refresh across account, profile, run, queue job and
 * attempt. Authorization headers, cookies, tokens, signatures and full
 * upstream error bodies are never part of the context.
 */
class RefreshLogger
{
    /** @param array<string,mixed> $context */
    public function event(string $event, RefreshRun $run, array $context = []): void
    {
        Log::info($event, [
            'event' => $event,
            'account_id' => $run->account_id,
            'account_key' => $run->account?->key,
            'profile_id' => $run->profile_id,
            'run_id' => $run->id,
            'job_id' => $context['job_id'] ?? null,
            'trigger' => $run->trigger,
            'deliveries' => $run->deliveries,
            'requests_used' => $run->requests_used,
            'waiting_seconds' => $run->waitingSeconds(),
        ] + $context);
    }
}
