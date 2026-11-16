<?php

namespace App\Modules\Sessions;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\UserDas;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reaches which session (and, from Task 4, which dossier): the scope
 * (Scopes) of the requested permission, as CrmVisibility does for the CRM.
 *
 *  - right not granted: nothing;
 *  - « Tout l'organisme » (all): everything in the organisme;
 *  - « Ses DAS » (das): das_id among the DAS he follows (Crm\Public\UserDas);
 *  - « Ses fiches » (own): he is responsible for it or created it.
 *
 * The scope of the right asked is used, never the name of a role: the view
 * scope filters lists and route bindings (404), update and delete their own.
 */
final class SessionsVisibility
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  on training_sessions
     * @return Builder<TModel>
     */
    public static function sessions(Builder $query, User $user, string $permission = Permissions::SESSIONS_VIEW): Builder
    {
        return self::scoped($query, $user, Scopes::of($user, $permission));
    }

    /**
     * $permission reaches $session (trashed or not).
     */
    public static function allows(User $user, string $permission, TrainingSession $session): bool
    {
        $scope = Scopes::of($user, $permission);

        return match ($scope) {
            null => false,
            AccessScope::All => true,
            AccessScope::Own => in_array($user->getKey(), [$session->responsible_user_id, $session->created_by], true),
            AccessScope::Das => in_array($session->das_id, UserDas::ids($user), true),
        };
    }

    /**
     * $permission lets the user create (or move) a record into DAS $dasId:
     * created by him it is his (Own); Das: one of the DAS he follows.
     */
    public static function allowsDas(User $user, string $permission, string $dasId): bool
    {
        return match (Scopes::of($user, $permission)) {
            null => false,
            AccessScope::All, AccessScope::Own => true,
            AccessScope::Das => in_array($dasId, UserDas::ids($user), true),
        };
    }

    /**
     * Responsible for it or created it (Own); das_id followed (Das).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scoped(Builder $query, User $user, ?AccessScope $scope): Builder
    {
        $model = $query->getModel();

        return match ($scope) {
            null => $query->whereRaw('false'),
            AccessScope::All => $query,
            AccessScope::Own => $query->where(fn (Builder $q) => $q
                ->where($model->qualifyColumn('responsible_user_id'), $user->getKey())
                ->orWhere($model->qualifyColumn('created_by'), $user->getKey())),
            AccessScope::Das => $query->whereIn($model->qualifyColumn('das_id'), UserDas::ids($user)),
        };
    }
}
