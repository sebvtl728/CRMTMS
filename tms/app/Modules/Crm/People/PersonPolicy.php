<?php

namespace App\Modules\Crm\People;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * Permissions crm.* plus the visibility rule (invisible people are already
 * 404 at route binding).
 */
class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::VIEW);
    }

    public function view(User $user, Person $person): bool
    {
        return $user->can(Permissions::VIEW) && $this->visible($user, $person);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::CREATE);
    }

    public function update(User $user, Person $person): bool
    {
        return $user->can(Permissions::UPDATE) && $this->visible($user, $person);
    }

    public function delete(User $user, Person $person): bool
    {
        return $user->can(Permissions::DELETE) && $this->visible($user, $person);
    }

    public function restore(User $user, Person $person): bool
    {
        return $this->delete($user, $person);
    }

    private function visible(User $user, Person $person): bool
    {
        return ! CrmVisibility::restricts($user)
            || CrmVisibility::people(Person::withTrashed(), $user)->whereKey($person->getKey())->exists();
    }
}
