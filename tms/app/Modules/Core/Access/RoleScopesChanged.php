<?php

namespace App\Modules\Core\Access;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The rights or scopes of a role changed (RoleMatrix: grant, revoke, scope,
 * reset), after commit. Modules whose data depend on a scope listen to it
 * (the CRM empties the followed DAS of the role's users when the role no
 * longer uses « Ses DAS »).
 */
class RoleScopesChanged
{
    use Dispatchable;

    public function __construct(public readonly RoleModel $role) {}
}
