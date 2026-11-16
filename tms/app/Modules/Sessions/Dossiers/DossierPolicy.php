<?php

namespace App\Modules\Sessions\Dossiers;

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsVisibility;

/**
 * Rights sessions.dossiers.* (creating is part of update), each read with
 * its own scope (SessionsVisibility). A dossier outside the view scope is
 * already a 404 at route binding. Its state (cancelled, done) is checked by
 * the writer and the transitions (409), except for the buttons.
 */
class DossierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::DOSSIERS_VIEW);
    }

    public function view(User $user, Dossier $dossier): bool
    {
        return SessionsVisibility::allows($user, Permissions::DOSSIERS_VIEW, $dossier);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::DOSSIERS_UPDATE);
    }

    public function update(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && SessionsVisibility::allows($user, Permissions::DOSSIERS_UPDATE, $dossier);
    }

    public function delete(User $user, Dossier $dossier): bool
    {
        return ! $dossier->trashed() && SessionsVisibility::allows($user, Permissions::DOSSIERS_DELETE, $dossier);
    }

    public function restore(User $user, Dossier $dossier): bool
    {
        return $dossier->trashed() && SessionsVisibility::allows($user, Permissions::DOSSIERS_DELETE, $dossier);
    }
}
