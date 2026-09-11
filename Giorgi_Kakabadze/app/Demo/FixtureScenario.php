<?php

namespace App\Demo;

use Illuminate\Support\Facades\Http;

/** Pushes one scenario document to the private fixture upstream. */
class FixtureScenario
{
    public function push(array $scenario): void
    {
        $scenario['started_at'] ??= microtime(true);

        Http::timeout(5)
            ->withBody(json_encode($scenario), 'application/json')
            ->post(config('fansapi.fixture.base_url').'/_scenario')
            ->throw();
    }

    public function waitUntilReady(int $seconds = 30): bool
    {
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            try {
                if (Http::timeout(2)->get(config('fansapi.fixture.base_url').'/_health')->successful()) {
                    return true;
                }
            } catch (\Throwable) {
                // keep polling
            }
            usleep(250_000);
        }

        return false;
    }

    /** The four cases the incident report asks for, as one scenario. */
    public static function incidentCases(): array
    {
        return [
            'profiles' => [
                'case-old-format' => ['format' => 'legacy', 'likes' => 120000, 'revision' => 10, 'delay_ms' => 0],
                'case-new-format' => ['format' => 'nested', 'likes' => 121000, 'revision' => 11, 'delay_ms' => 0],
                'case-throttled' => ['status' => 429, 'body' => '', 'delay_ms' => 0],           // no Retry-After
                'case-server-error' => ['status' => 500, 'body' => '', 'delay_ms' => 0],        // empty body
            ],
        ];
    }
}
