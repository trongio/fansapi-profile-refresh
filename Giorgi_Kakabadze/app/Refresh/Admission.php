<?php

namespace App\Refresh;

use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Two independent gates in front of every upstream request.
 *
 *  - ACCOUNT cooldown: stops one account's burst from hammering the upstream
 *    after it has been throttled. Reserved workers give account B capacity;
 *    the cooldown is what stops account A from spending the upstream on it.
 *  - WORKSPACE cooldown: the managed provider bills a quota shared by every
 *    key in a workspace, and cached reads consume it too, so isolating
 *    accounts cannot by itself guarantee A's burst leaves room for B.
 *
 * Both updates are monotonic - an overlapping request can only ever push a
 * cooldown further out, never pull it in.
 */
class Admission
{
    /** Seconds the caller must wait, or null when admitted. */
    public function check(Account $account): ?int
    {
        $now = Carbon::now();

        $accountUntil = $account->fresh()->cooldown_until;
        $workspaceUntil = $this->workspaceCooldownUntil($account);

        $until = max(
            $accountUntil?->getTimestamp() ?? 0,
            $workspaceUntil,
        );

        $wait = $until - $now->getTimestamp();

        return $wait > 0 ? $wait : null;
    }

    /** Monotonic in SQL: GREATEST never moves a cooldown backwards. */
    public function coolDownAccount(Account $account, int $seconds): void
    {
        $until = Carbon::now()->addSeconds($seconds)->toDateTimeString();

        DB::update(
            'UPDATE accounts SET cooldown_until = GREATEST(COALESCE(cooldown_until, ?), ?) WHERE id = ?',
            [$until, $until, $account->id],
        );
    }

    /** Monotonic in Redis: the compare-and-set happens inside one Lua call. */
    public function coolDownWorkspace(Account $account, int $seconds): void
    {
        $until = Carbon::now()->addSeconds($seconds)->getTimestamp();

        Redis::eval(
            "local current = tonumber(redis.call('get', KEYS[1]) or '0')\n".
            "if tonumber(ARGV[1]) > current then redis.call('set', KEYS[1], ARGV[1], 'EX', ARGV[2]) end\n".
            'return 1',
            1,
            $this->workspaceKey($account),
            $until,
            max(1, $seconds) + 60,
        );
    }

    private function workspaceCooldownUntil(Account $account): int
    {
        return (int) Redis::get($this->workspaceKey($account));
    }

    private function workspaceKey(Account $account): string
    {
        return 'fansapi:workspace-cooldown:'.$account->workspace;
    }
}
