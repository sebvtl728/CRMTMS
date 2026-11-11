<?php

namespace App\Modules\Core\Users;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

        // Deny by default until roles and permissions exist (Task 5 replaces
        // this definition with the users.manage permission of the role matrix).
        Gate::define('users.manage', fn (User $user) => false);
    }
}
