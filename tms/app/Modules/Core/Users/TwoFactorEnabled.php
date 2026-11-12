<?php

namespace App\Modules\Core\Users;

use Illuminate\Foundation\Events\Dispatchable;

/** Two-factor authentication was confirmed for the user (audit: auth.2fa_enabled). */
class TwoFactorEnabled
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
