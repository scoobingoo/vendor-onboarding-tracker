<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Business logic always asks now() for the time, so pinning Carbon's
        // clock here pins the whole app (see config/tracker.php).
        if ($fixedNow = config('tracker.fixed_now')) {
            Carbon::setTestNow(Carbon::parse($fixedNow));
        }
    }
}
