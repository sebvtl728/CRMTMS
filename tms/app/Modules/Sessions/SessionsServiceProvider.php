<?php

namespace App\Modules\Sessions;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use Illuminate\Support\ServiceProvider;

/**
 * Module Sessions (lot 2a): dossiers, sessions de formation, inscriptions.
 * It reaches the CRM through App\Modules\Crm\Public only and the catalogue
 * through Trainings; neither depends on it (registries and tags of theirs).
 */
class SessionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In register(): the catalogue must be complete before
        // AccessServiceProvider::boot() defines one gate per permission.
        foreach (Permissions::GROUPS as $group => $permissions) {
            CorePermissions::registerModule(
                'sessions.'.explode('.', $permissions[0])[1],
                $permissions,
                fn (Role $role): array => array_values(array_intersect(Permissions::defaultsFor($role), $permissions)),
                Permissions::labels(),
                $group,
                array_values(array_intersect(Permissions::scoped(), $permissions)),
                fn (Role $role): array => array_intersect_key(Permissions::defaultScopesFor($role), array_flip($permissions)),
            );
        }
    }
}
