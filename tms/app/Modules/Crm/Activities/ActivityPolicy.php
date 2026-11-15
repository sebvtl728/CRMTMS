<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * Permissions crm.activities.* plus the visibility of what the action hangs
 * on (invisible actions are already 404 at route binding). Like a note, an
 * action is edited or completed with crm.activities.update, removed with
 * crm.activities.delete, never on a trashed fiche (whose actions only
 * crm.fiches.delete reads, see the route binding).
 */
class ActivityPolicy
{
    public function view(User $user, Activity $activity): bool
    {
        return CrmVisibility::allows($user, Permissions::ACTIVITIES_VIEW, $activity);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::ACTIVITIES_UPDATE);
    }

    public function update(User $user, Activity $activity): bool
    {
        return ! $activity->ficheTrashed() && CrmVisibility::allows($user, Permissions::ACTIVITIES_UPDATE, $activity);
    }

    public function delete(User $user, Activity $activity): bool
    {
        return ! $activity->ficheTrashed() && CrmVisibility::allows($user, Permissions::ACTIVITIES_DELETE, $activity);
    }
}
