<?php

namespace App\Demo;

use App\Models\Account;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Demo-scoped seed and reset.
 *
 * Reset only touches this app's own tables and only this app's queue keys.
 * There is no FLUSHALL and no global prune anywhere in the project.
 */
class DemoData
{
    /** Every demo profile starts here: the last valid data from the incident. */
    public const SEED_LIKES = 120000;

    public const SEED_REVISION = 9;

    /** The four cases the incident report asks to be recorded. */
    public const CASE_PROFILES = ['case-old-format', 'case-new-format', 'case-throttled', 'case-server-error'];

    public function reset(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['refresh_attempts', 'refresh_runs', 'profiles', 'accounts', 'failed_jobs', 'jobs'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->clearOwnQueues();
    }

    public function seed(): void
    {
        $accountA = Account::create([
            'key' => 'A', 'label' => 'Account A (busy)', 'queue' => 'refresh-a',
            'source' => 'fixture', 'workspace' => 'demo',
        ]);
        $accountB = Account::create([
            'key' => 'B', 'label' => 'Account B (healthy)', 'queue' => 'refresh-b',
            'source' => 'fixture', 'workspace' => 'demo',
        ]);
        $live = Account::create([
            'key' => 'LIVE', 'label' => 'Managed provider (live)', 'queue' => 'refresh-a',
            'source' => 'ofapi', 'workspace' => config('fansapi.ofapi.workspace'),
        ]);

        foreach (range(1, 12) as $n) {
            $this->makeProfile($accountA, sprintf('a-%02d', $n));
        }
        foreach (range(1, 4) as $n) {
            $this->makeProfile($accountB, sprintf('b-%02d', $n));
        }
        foreach (self::CASE_PROFILES as $username) {
            $this->makeProfile($accountA, $username);
        }

        // The real target, only ever refreshed by the explicit live action.
        $this->makeProfile($live, 'madison420ivy');
    }

    private function makeProfile(Account $account, string $username): Profile
    {
        return Profile::create([
            'account_id' => $account->id,
            'username' => $username,
            'display_name' => $username,
            'likes' => self::SEED_LIKES,
            'revision' => self::SEED_REVISION,
            'snapshot' => ['likes' => self::SEED_LIKES, 'seeded' => true],
            'snapshot_source' => 'seed',
            // Deliberately not due: demo runs are explicit, so the every-minute
            // scheduler cannot enqueue work in the middle of a measured workload.
            'next_refresh_at' => now()->addDay(),
        ]);
    }

    /** Project-scoped: only this app's queue keys, never the whole database. */
    private function clearOwnQueues(): void
    {
        $prefix = (string) config('database.redis.options.prefix');

        foreach (['refresh-a', 'refresh-b', 'refresh-broken', 'default'] as $queue) {
            foreach (Redis::keys("queues:{$queue}*") as $key) {
                $name = $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
                Redis::del($name);
            }
        }
    }
}
