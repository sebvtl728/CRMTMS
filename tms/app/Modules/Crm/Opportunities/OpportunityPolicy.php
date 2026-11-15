<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * Permissions crm.opportunities.* within their scope (CrmVisibility::allows;
 * opportunities outside the view scope are already 404 at route binding).
 */
class OpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::OPPORTUNITIES_VIEW);
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return CrmVisibility::allows($user, Permissions::OPPORTUNITIES_VIEW, $opportunity);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::OPPORTUNITIES_UPDATE);
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return ! $this->ficheTrashed($opportunity) && CrmVisibility::allows($user, Permissions::OPPORTUNITIES_UPDATE, $opportunity);
    }

    public function delete(User $user, Opportunity $opportunity): bool
    {
        return ! $this->ficheTrashed($opportunity) && CrmVisibility::allows($user, Permissions::OPPORTUNITIES_DELETE, $opportunity);
    }

    /**
     * Like the fiche itself: nothing is edited on a trashed entreprise or person.
     */
    private function ficheTrashed(Opportunity $opportunity): bool
    {
        $fiche = $opportunity->organisation_id !== null ? $opportunity->organisation : $opportunity->person;

        return $fiche === null || $fiche->trashed();
    }
}
