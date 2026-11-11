<?php

namespace App\Modules\Core\Access;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Global permission name (catalogue, see Permissions). Not tenant data: which
 * role holds it is stored per tenant in role_has_permissions (RLS).
 *
 * @property string $id
 * @property string $name
 * @property string $guard_name
 */
class PermissionModel extends SpatiePermission
{
    use HasUuids;

    protected $table = 'permissions';
}
