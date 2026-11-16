<?php

namespace App\Modules\Catalogue\Brochures;

use App\Modules\Catalogue\Permissions;
use App\Modules\Core\Users\User;

/**
 * Permissions catalogue.* on a brochure (one of another organisme is already
 * a 404 at route binding): reading and downloading = view, depositing and
 * changing = manage, trash = delete.
 */
class BrochurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::VIEW);
    }

    public function view(User $user, Brochure $brochure): bool
    {
        return $user->can(Permissions::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::MANAGE);
    }

    public function update(User $user, Brochure $brochure): bool
    {
        return $user->can(Permissions::MANAGE);
    }

    public function delete(User $user, Brochure $brochure): bool
    {
        return $user->can(Permissions::DELETE);
    }
}
