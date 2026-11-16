<?php

namespace App\Modules\Sessions\Sessions;

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsVisibility;

/**
 * Rights sessions.sessions.* (creating is part of update), each read with
 * its own scope (SessionsVisibility). A session outside the view scope is
 * already a 404 at route binding. Whether it is cancelled is checked by the
 * writer (409, not a matter of rights) except for the buttons.
 */
class SessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::SESSIONS_VIEW);
    }

    public function view(User $user, TrainingSession $session): bool
    {
        return SessionsVisibility::allows($user, Permissions::SESSIONS_VIEW, $session);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::SESSIONS_UPDATE);
    }

    public function update(User $user, TrainingSession $session): bool
    {
        return ! $session->trashed() && SessionsVisibility::allows($user, Permissions::SESSIONS_UPDATE, $session);
    }

    public function delete(User $user, TrainingSession $session): bool
    {
        return ! $session->trashed() && SessionsVisibility::allows($user, Permissions::SESSIONS_DELETE, $session);
    }

    public function restore(User $user, TrainingSession $session): bool
    {
        return $session->trashed() && SessionsVisibility::allows($user, Permissions::SESSIONS_DELETE, $session);
    }
}
