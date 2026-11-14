<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Users\InvitationAccepted;
use App\Modules\Core\Users\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Rebound in boot(): spatie registers its own singleton in its boot().
        $this->app->singleton(PermissionRegistrar::class, TenantPermissionRegistrar::class);

        // A deactivated account is refused everything, whatever its role.
        Gate::before(function (mixed $user): ?bool {
            return $user instanceof User && ! $user->isActive() ? false : null;
        });

        // One gate per catalogue permission, resolved through the user's role
        // in the current tenant (spatie team = tenant).
        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->checkPermissionTo($permission));
        }

        // « Rôles et droits »: 60 changes a minute per administrator.
        RateLimiter::for('roles-write', fn (Request $request) => Limit::perMinute(60)->by('roles-write:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // A long-running worker serves many jobs of the same organisme: reload
        // the permissions at each job (changed meanwhile by the web).
        Event::listen(JobProcessing::class, fn () => $this->app->make(PermissionRegistrar::class)->clearPermissionsCollection());

        // The invited role becomes the primary role once the account is activated.
        Event::listen(InvitationAccepted::class, function (InvitationAccepted $event): void {
            $event->user->assignPrimaryRole($event->invitation->role);
        });
    }
}
