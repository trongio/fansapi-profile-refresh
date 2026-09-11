<?php

namespace App\Demo;

/**
 * Per-job memory sampling, taken INSIDE the worker process.
 *
 * memory_reset_peak_usage() gives a per-attempt peak; it resets a statistic,
 * it does not free anything. RSS is read from /proc/self/statm because PHP's
 * own numbers only account for the Zend allocator.
 */
class MemoryProbe
{
    public const PATH = 'storage/logs/memory-samples.jsonl';

    public static function enabled(): bool
    {
        return (bool) config('fansapi.memory_probe');
    }

    public static function before(): void
    {
        if (self::enabled() && function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }

    public static function after(string $jobId, string $outcome): void
    {
        if (! self::enabled()) {
            return;
        }

        file_put_contents(base_path(self::PATH), json_encode([
            'at' => microtime(true),
            'pid' => getmypid(),
            'job_id' => $jobId,
            'outcome' => $outcome,
            'php_current_bytes' => memory_get_usage(true),
            'php_peak_bytes' => memory_get_peak_usage(true),
            'rss_bytes' => self::rssBytes(),
        ])."\n", FILE_APPEND | LOCK_EX);
    }

    private static function rssBytes(): int
    {
        $statm = @file_get_contents('/proc/self/statm');
        if ($statm === false) {
            return 0;
        }

        $fields = explode(' ', $statm);

        return (int) ($fields[1] ?? 0) * 4096; // resident pages
    }
}
