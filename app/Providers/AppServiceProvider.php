<?php

namespace App\Providers;

use App\Listeners\LinkDeadLetterToRun;
use App\Listeners\SampleWorkerMemory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen([JobProcessing::class, JobProcessed::class, JobFailed::class], SampleWorkerMemory::class);
        Event::listen(JobFailed::class, LinkDeadLetterToRun::class);
    }
}
