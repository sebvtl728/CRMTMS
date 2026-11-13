<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Tenancy\Tenant;

/**
 * Creates the roles of a tenant with the default permission matrix
 * (called by tms:install and tms:tenant:create).
 *
 * Idempotent, delegated to PermissionSync: missing permissions and roles are
 * created, and a role only receives the default permissions never offered to
 * it before (a tenant's customised matrix is never overwritten).
 */
final class RoleSeeder
{
    public const GUARD = 'web';

    public static function seedFor(Tenant $tenant): void
    {
        PermissionSync::forTenant($tenant);
    }
}
