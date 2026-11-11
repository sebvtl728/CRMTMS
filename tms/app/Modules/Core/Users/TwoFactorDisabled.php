<?php

namespace App\Modules\Core\Users;

use Illuminate\Foundation\Events\Dispatchable;

/** Two-factor authentication was disabled for the user (audit: auth.2fa_disabled). */
class TwoFactorDisabled
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
