<?php

namespace App\Providers;

use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Platform operators only (queues of every organisme), active and with 2FA, never by e-mail list.
        Gate::define('viewHorizon', fn ($user = null): bool => $user instanceof User && $user->is_platform_admin && $user->isActive() && $user->hasTwoFactorEnabled());
    }
}
