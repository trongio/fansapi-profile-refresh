<?php

namespace App\Refresh;

/**
 * Raised so the job can hand a permanent failure to Laravel's own failure
 * pipeline. That writes the durable failed_jobs row which IS our dead-letter
 * queue, and carries the uuid we link back to the refresh run.
 */
class TerminalRefreshFailure extends \RuntimeException
{
    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct("{$category}: {$message}");
    }
}
