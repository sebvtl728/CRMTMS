<?php

namespace App\Modules\Core\Users;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once an invitation has been used to activate an account (role
 * assignment hooks in here).
 */
class InvitationAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly User $user,
    ) {}
}
