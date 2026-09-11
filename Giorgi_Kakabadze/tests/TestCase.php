<?php

namespace Tests;

use App\Demo\FixtureScenario;
use App\Models\Account;
use App\Models\Profile;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase rolls the database back, but Redis is not in that
        // transaction. Without this, a job left queued by one test would be
        // popped by the next one. Only this app's own keys are removed, on the
        // dedicated test Redis databases configured in phpunit.xml.
        $this->clearOwnRedisKeys();
    }

    protected function clearOwnRedisKeys(): void
    {
        $prefix = (string) config('database.redis.options.prefix');

        foreach (['queues:*', '*profile-lease*', 'fansapi:*'] as $pattern) {
            foreach (Redis::keys($pattern) as $key) {
                Redis::del($prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key);
            }
        }
    }

    protected function account(string $key = 'A', array $attributes = []): Account
    {
        return Account::factory()->key($key)->create($attributes);
    }

    protected function profile(Account $account, string $username, array $attributes = []): Profile
    {
        return Profile::factory()->for($account)->create(['username' => $username, 'display_name' => $username] + $attributes);
    }

    /** Push a scenario to the private fixture upstream used by the tests. */
    protected function scenario(array $scenario): void
    {
        app(FixtureScenario::class)->push($scenario);
    }

    /**
     * Run exactly one job with a real Redis reservation, so releases, delivery
     * counting and the reservation itself behave as they do in a worker.
     */
    protected function workOnce(string $queue = 'refresh-a'): int
    {
        return Artisan::call('queue:work', [
            '--once' => true,
            '--queue' => $queue,
            '--tries' => 100,
        ]);
    }
}
