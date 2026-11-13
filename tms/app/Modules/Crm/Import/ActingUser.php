<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Auth;

/**
 * Runs queued work as the user who asked for it: fiches get his
 * created_by / updated_by, notes his authorship, the journal his name.
 */
final class ActingUser
{
    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function run(?User $user, callable $fn): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->user();

        if ($user !== null) {
            $guard->setUser($user);
        }

        try {
            return $fn();
        } finally {
            if ($previous instanceof User) {
                $guard->setUser($previous);
            } elseif ($user !== null) {
                $guard->forgetUser();
            }
        }
    }
}
