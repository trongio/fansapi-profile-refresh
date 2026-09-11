<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Refresh\QueueStats;
use Illuminate\Http\JsonResponse;

/**
 * Feeds the per-account activity panel. Everything is either an aggregate in
 * SQL or an O(1) Redis size call - no Redis payload is decoded on a poll and
 * no profile snapshot is loaded.
 */
class ActivityController extends Controller
{
    public function __invoke(QueueStats $stats): JsonResponse
    {
        return response()->json([
            'updated_at' => now()->utc()->toIso8601String(),
            'mode' => strtoupper((string) config('fansapi.source')),
            'stale_leases' => $stats->staleLeases(),
            'accounts' => Account::orderBy('key')->get()->map(fn (Account $a) => $stats->accountRow($a))->values(),
        ]);
    }
}
