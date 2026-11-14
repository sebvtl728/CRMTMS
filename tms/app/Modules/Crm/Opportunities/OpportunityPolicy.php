<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * Permissions crm.* plus the DAS visibility (invisible opportunities are
 * already 404 at route binding).
 */
class OpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::VIEW);
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $user->can(Permissions::VIEW) && $this->visible($user, $opportunity);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::CREATE);
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $user->can(Permissions::UPDATE) && $this->visible($user, $opportunity) && ! $this->ficheTrashed($opportunity);
    }

    public function delete(User $user, Opportunity $opportunity): bool
    {
        return $user->can(Permissions::DELETE) && $this->visible($user, $opportunity) && ! $this->ficheTrashed($opportunity);
    }

    /**
     * Like the fiche itself: nothing is edited on a trashed entreprise or person.
     */
    private function ficheTrashed(Opportunity $opportunity): bool
    {
        $fiche = $opportunity->organisation_id !== null ? $opportunity->organisation : $opportunity->person;

        return $fiche === null || $fiche->trashed();
    }

    private function visible(User $user, Opportunity $opportunity): bool
    {
        return ! CrmVisibility::restricts($user)
            || CrmVisibility::opportunities(Opportunity::withTrashed(), $user)->whereKey($opportunity->getKey())->exists();
    }
}
