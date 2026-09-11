<?php

namespace App\Providers;

use App\Demo\MemoryProbe;
use App\Jobs\RefreshProfileJob;
use App\Refresh\DeadLetters;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Memory scenario probe. Off unless MEMORY_PROBE=1 in the worker env.
        Queue::before(fn (JobProcessing $event) => MemoryProbe::before());
        Queue::after(fn (JobProcessed $event) => MemoryProbe::after($event->job->uuid(), 'processed'));

        // The failed-job uuid is only available on this event, so this is where
        // a dead letter gets linked back to its logical refresh run.
        Queue::failing(function (JobFailed $event): void {
            MemoryProbe::after($event->job->uuid(), 'failed');

            $serialized = $event->job->payload()['data']['command'] ?? null;
            $job = is_string($serialized) ? @unserialize($serialized) : null;

            if ($job instanceof RefreshProfileJob) {
                app(DeadLetters::class)->record($job->runId, $event->job->uuid(), $event->exception);
            }
        });
    }
}
