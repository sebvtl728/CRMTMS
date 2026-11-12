<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // Every password rule (activation, reset, change) uses Password::defaults().
        Password::defaults(fn () => Password::min(12)->uncompromised());

        // The end-to-end stack serves the production build even while the Vite dev server runs.
        if (! config('tms.vite_hot')) {
            Vite::useHotFile(storage_path('framework/vite.hot'));
        }
    }
}
