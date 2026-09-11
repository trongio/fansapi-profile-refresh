<?php

namespace App\Listeners;

use App\Jobs\RefreshProfileJob;
use App\Refresh\DeadLetters;
use Illuminate\Queue\Events\JobFailed;

/**
 * The failed-job uuid only exists on this event, so this is where a dead
 * letter gets linked back to its logical refresh run.
 */
class LinkDeadLetterToRun
{
    public function __construct(private readonly DeadLetters $deadLetters) {}

    public function handle(JobFailed $event): void
    {
        $serialized = $event->job->payload()['data']['command'] ?? null;
        $job = is_string($serialized) ? @unserialize($serialized) : null;

        if ($job instanceof RefreshProfileJob) {
            $this->deadLetters->record($job->runId, $event->job->uuid(), $event->exception);
        }
    }
}
