<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * Permissions crm.* plus the visibility of what the action hangs on
 * (invisible actions are already 404 at route binding). Like a note, an
 * action is edited, completed or removed with crm.update, never on a
 * trashed fiche.
 */
class ActivityPolicy
{
    public function view(User $user, Activity $activity): bool
    {
        return $user->can(Permissions::VIEW) && $this->visible($user, $activity);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::CREATE);
    }

    public function update(User $user, Activity $activity): bool
    {
        return $user->can(Permissions::UPDATE) && $this->visible($user, $activity) && ! $activity->ficheTrashed();
    }

    public function delete(User $user, Activity $activity): bool
    {
        return $this->update($user, $activity);
    }

    private function visible(User $user, Activity $activity): bool
    {
        return ! CrmVisibility::restricts($user)
            || CrmVisibility::activities(Activity::withTrashed(), $user)->whereKey($activity->getKey())->exists();
    }
}
