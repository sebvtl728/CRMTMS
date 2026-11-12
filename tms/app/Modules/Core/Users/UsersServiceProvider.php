<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class UsersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // config/auth.php: providers.users.driver = active_users
        Auth::provider('active_users', function (Application $app, array $config) {
            /** @var array{model: class-string<User>} $config */
            return new ActiveUserProvider($app['hash'], $config['model']);
        });

        // Second login step: 5 attempts per minute per pending login (per user).
        RateLimiter::for('two-factor', function (Request $request) {
            $tenant = app(TenantContext::class)->current()->id ?? 'none';

            return Limit::perMinute(5)->by($tenant.'|'.$request->session()->get('login.id', $request->ip()));
        });

        // Enrolment confirmation: 5 attempts per minute per user.
        RateLimiter::for('two-factor-setup', fn (Request $request) => Limit::perMinute(5)->by('setup|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
