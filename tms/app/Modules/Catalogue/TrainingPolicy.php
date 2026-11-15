<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\Users\User;

/**
 * Permissions catalogue.* (a training of another organisme is already a
 * 404 at route binding).
 */
class TrainingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::VIEW);
    }

    public function view(User $user, Training $training): bool
    {
        return $user->can(Permissions::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::MANAGE);
    }

    public function update(User $user, Training $training): bool
    {
        return $user->can(Permissions::MANAGE);
    }

    public function delete(User $user, Training $training): bool
    {
        return $user->can(Permissions::DELETE);
    }
}
