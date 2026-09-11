<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Nothing to register by hand: the two queue listeners in app/Listeners are
 * picked up by Laravel's event discovery from their handle() type hints.
 * Registering them here as well would fire each of them twice per job.
 */
class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}
