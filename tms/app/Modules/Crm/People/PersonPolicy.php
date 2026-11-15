<?php

namespace App\Modules\Crm\People;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * Permissions crm.fiches.* plus the visibility rule (invisible people are already
 * 404 at route binding).
 */
class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::FICHES_VIEW);
    }

    public function view(User $user, Person $person): bool
    {
        return CrmVisibility::allows($user, Permissions::FICHES_VIEW, $person);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::FICHES_UPDATE);
    }

    public function update(User $user, Person $person): bool
    {
        // An anonymised fiche is never filled again.
        return $person->anonymised_at === null && CrmVisibility::allows($user, Permissions::FICHES_UPDATE, $person);
    }

    /** Data export, anonymisation, « Conserver ». */
    public function gdpr(User $user, Person $person): bool
    {
        return $user->can(Permissions::GDPR) && CrmVisibility::allows($user, Permissions::FICHES_VIEW, $person);
    }

    public function delete(User $user, Person $person): bool
    {
        return CrmVisibility::allows($user, Permissions::FICHES_DELETE, $person);
    }

    public function restore(User $user, Person $person): bool
    {
        return $this->delete($user, $person);
    }
}
