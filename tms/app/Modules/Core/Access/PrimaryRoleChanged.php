<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The primary role of a user changed (User::assignPrimaryRole). The audit
 * journal (access.role_changed) listens to it.
 */
class PrimaryRoleChanged
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly ?Role $previous,
        public readonly Role $role,
    ) {}
}
