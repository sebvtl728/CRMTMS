<?php

namespace App\Modules\Crm\Organisations;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * Permissions crm.* plus the visibility rule (the fiche must be visible to
 * the user; invisible fiches are already 404 at route binding).
 */
class OrganisationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::VIEW);
    }

    public function view(User $user, Organisation $organisation): bool
    {
        return $user->can(Permissions::VIEW) && $this->visible($user, $organisation);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::CREATE);
    }

    public function update(User $user, Organisation $organisation): bool
    {
        return $user->can(Permissions::UPDATE) && $this->visible($user, $organisation);
    }

    public function delete(User $user, Organisation $organisation): bool
    {
        return $user->can(Permissions::DELETE) && $this->visible($user, $organisation);
    }

    public function restore(User $user, Organisation $organisation): bool
    {
        return $this->delete($user, $organisation);
    }

    private function visible(User $user, Organisation $organisation): bool
    {
        return ! CrmVisibility::restricts($user)
            || CrmVisibility::organisations(Organisation::withTrashed(), $user)->whereKey($organisation->getKey())->exists();
    }
}
