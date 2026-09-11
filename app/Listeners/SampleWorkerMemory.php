<?php

namespace App\Listeners;

use App\Demo\MemoryProbe;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/** Memory scenario probe. Inert unless MEMORY_PROBE=1 in the worker env. */
class SampleWorkerMemory
{
    public function handle(JobProcessing|JobProcessed|JobFailed $event): void
    {
        match (true) {
            $event instanceof JobProcessing => MemoryProbe::before(),
            $event instanceof JobProcessed => MemoryProbe::after($event->job->uuid(), 'processed'),
            $event instanceof JobFailed => MemoryProbe::after($event->job->uuid(), 'failed'),
        };
    }
}
