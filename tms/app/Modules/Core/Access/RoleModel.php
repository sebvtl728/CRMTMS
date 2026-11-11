<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role record of one tenant (spatie "team" = tenant). Its name is a Role
 * enum value. Under RLS: a tenant never sees another tenant's roles.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $guard_name
 */
class RoleModel extends SpatieRole
{
    use BelongsToTenant, HasUuids;

    protected $table = 'roles';
}
