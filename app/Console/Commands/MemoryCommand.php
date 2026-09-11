<?php

namespace App\Console\Commands;

use App\Demo\FixtureScenario;
use App\Demo\MemoryProbe;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshRun;
use App\Refresh\RefreshDispatcher;
use Illuminate\Console\Command;

/**
 * A short, local memory scenario: N fixture jobs through the persistent
 * account-A worker, mixing normal, near-limit and failing responses.
 *
 * The question is whether a warmed-up worker PLATEAUS, not whether it stays
 * under a cap. A memory cap or a restart after every job would prove nothing.
 * Never points at the live provider.
 */
class MemoryCommand extends Command
{
    protected $signature = 'fans:memory
        {--jobs=100 : number of fixture refreshes}
        {--wait=180 : seconds to wait for the worker}
        {--warmup=10 : samples to ignore before comparing}
        {--reuse : keep the existing scenario profiles, so stored snapshots from a previous pass are present}
        {--json= : write the machine-readable result here}';

    protected $description = 'Run a bounded fixture memory scenario through one persistent worker.';

    public function handle(FixtureScenario $fixtures, RefreshDispatcher $dispatcher): int
    {
        if (! MemoryProbe::enabled()) {
            $this->error('Set MEMORY_PROBE=1 in .env and restart the horizon container first.');

            return self::FAILURE;
        }

        $count = (int) $this->option('jobs');
        // Each pass serves a higher revision so a reused profile accepts the write.
        $pass = $this->option('reuse') ? (int) RefreshRun::whereHas('account', fn ($q) => $q->where('key', 'MEM'))->max('accepted_revision') - 10 : 0;
        $pass = max(0, $pass);

        $account = Account::firstOrCreate(
            ['key' => 'MEM'],
            ['label' => 'Memory scenario', 'queue' => 'refresh-a', 'source' => 'fixture', 'workspace' => 'demo'],
        );

        if (! $this->option('reuse')) {
            Profile::where('account_id', $account->id)->delete();
        }

        $profiles = [];
        $specs = [];
        for ($i = 1; $i <= $count; $i++) {
            $username = sprintf('mem-%03d', $i);
            // With --reuse the row already carries whatever snapshot the last
            // pass stored, which is the realistic steady state for a worker.
            $profile = Profile::firstOrCreate(
                ['account_id' => $account->id, 'username' => $username],
                ['display_name' => $username, 'likes' => 120000, 'revision' => 9],
            );
            $profile->forceFill(['pending_run_id' => null, 'terminal_failed_at' => null])->save();
            $profiles[] = $profile;

            $specs[$username] = match (true) {
                // ~5% failures
                $i % 20 === 0 => ['status' => 500, 'body' => '', 'delay_ms' => 0],
                // ~15% near the 1 MiB cap but still valid
                $i % 7 === 0 => ['format' => 'nested', 'likes' => 121000, 'revision' => 11 + $pass, 'padding_bytes' => 900_000, 'delay_ms' => 0],
                default => ['format' => 'nested', 'likes' => 121000, 'revision' => 11 + $pass, 'delay_ms' => 0],
            };
        }

        if (! $fixtures->waitUntilReady()) {
            $this->error('fixture upstream is not reachable');

            return self::FAILURE;
        }
        $fixtures->push(['profiles' => $specs]);

        @unlink(base_path(MemoryProbe::PATH));

        foreach ($profiles as $profile) {
            $dispatcher->enqueue($profile, 'cli');
        }
        $this->line("queued {$count} fixture refreshes on {$account->queue}");

        $deadline = time() + (int) $this->option('wait');
        while (time() < $deadline) {
            $pending = Profile::where('account_id', $account->id)->whereNotNull('pending_run_id')->count();
            if ($pending === 0) {
                break;
            }
            usleep(500_000);
        }

        $samples = $this->readSamples();
        if ($samples === []) {
            $this->error('no memory samples were written; is the horizon container running with MEMORY_PROBE=1?');

            return self::FAILURE;
        }

        $warmup = (int) $this->option('warmup');
        $measured = array_slice($samples, $warmup);
        $byPid = [];
        foreach ($measured as $sample) {
            $byPid[$sample['pid']][] = $sample;
        }

        $rows = [];
        foreach ($byPid as $pid => $pidSamples) {
            $rows[] = [
                $pid,
                count($pidSamples),
                $this->mib(min(array_column($pidSamples, 'php_current_bytes'))).' - '.$this->mib(max(array_column($pidSamples, 'php_current_bytes'))),
                $this->mib(max(array_column($pidSamples, 'php_peak_bytes'))),
                $this->mib(min(array_column($pidSamples, 'rss_bytes'))).' - '.$this->mib(max(array_column($pidSamples, 'rss_bytes'))),
            ];
        }

        $window = max(1, (int) floor(count($measured) / 4));
        $firstAvg = $this->avg(array_slice($measured, 0, $window), 'rss_bytes');
        $lastAvg = $this->avg(array_slice($measured, -$window), 'rss_bytes');

        $this->newLine();
        $this->table(['worker pid', 'jobs', 'PHP current (MiB)', 'PHP peak (MiB)', 'RSS (MiB)'], $rows);

        $this->table(['measure', 'value'], [
            ['jobs measured (after warm-up)', count($measured)],
            ['distinct worker PIDs (restarts)', count($byPid)],
            ['first quarter mean RSS', $this->mib($firstAvg).' MiB'],
            ['last quarter mean RSS', $this->mib($lastAvg).' MiB'],
            ['drift', sprintf('%+.2f MiB', ($lastAvg - $firstAvg) / 1048576)],
            ['php memory_limit', ini_get('memory_limit').' (this CLI; workers use the same image)'],
            ['horizon worker recycle threshold', config('horizon.defaults.supervisor-account-a.memory').' MiB'],
            ['failed refreshes in this run', RefreshRun::where('account_id', $account->id)->whereIn('status', ['failed', 'dead_lettered'])->count()],
        ]);

        $this->writeJson([
            'jobs_requested' => $count,
            'samples_measured' => count($measured),
            'warmup_skipped' => $warmup,
            'workers' => array_map(fn ($pid, $s) => [
                'pid' => $pid,
                'jobs' => count($s),
                'php_peak_bytes' => max(array_column($s, 'php_peak_bytes')),
                'rss_max_bytes' => max(array_column($s, 'rss_bytes')),
            ], array_keys($byPid), $byPid),
            'rss_first_quarter_mean_bytes' => (int) $firstAvg,
            'rss_last_quarter_mean_bytes' => (int) $lastAvg,
            'php_memory_limit' => ini_get('memory_limit'),
            'horizon_worker_recycle_mib' => config('horizon.defaults.supervisor-account-a.memory'),
        ]);

        return self::SUCCESS;
    }

    private function readSamples(): array
    {
        $path = base_path(MemoryProbe::PATH);
        if (! is_file($path)) {
            return [];
        }

        $samples = [];
        // Line by line: the sample file is never loaded as one string.
        $handle = fopen($path, 'r');
        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $samples[] = $decoded;
            }
        }
        fclose($handle);

        return $samples;
    }

    private function avg(array $samples, string $key): float
    {
        return $samples === [] ? 0.0 : array_sum(array_column($samples, $key)) / count($samples);
    }

    private function mib(float|int $bytes): string
    {
        return number_format($bytes / 1048576, 1);
    }

    private function writeJson(array $payload): void
    {
        $path = $this->option('json');
        if ($path === null) {
            return;
        }
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));
        $this->line("wrote {$path}");
    }
}
