<?php

namespace App\Modules\Crm\Public;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Visibility\FollowedDas;

/**
 * DAS followed by a user (« Ses DAS » scope of the other modules: a session
 * or a dossier of one of these DAS).
 */
final class UserDas
{
    /**
     * @return list<string> ids of the DAS followed by $user
     */
    public static function ids(User $user): array
    {
        return FollowedDas::ids($user);
    }
}
