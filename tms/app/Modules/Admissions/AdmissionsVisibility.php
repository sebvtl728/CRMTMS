<?php

namespace App\Modules\Admissions;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\UserDas;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reaches which candidature: the scope (Scopes) of the requested right,
 * never the name of a role. « Ses fiches » (own): responsible for it or
 * created it; « Ses DAS » (das): its das_id among the DAS he follows. The
 * candidature has its own scope, independent of the CRM's.
 */
final class AdmissionsVisibility
{
    /**
     * @param  Builder<Candidature>  $query
     * @return Builder<Candidature>
     */
    public static function candidatures(Builder $query, User $user, string $permission = Permissions::CANDIDATURES_VIEW): Builder
    {
        $model = $query->getModel();

        return match (Scopes::of($user, $permission)) {
            null, AccessScope::Trainer => $query->whereRaw('false'),
            AccessScope::All => $query,
            AccessScope::Own => $query->where(fn (Builder $q) => $q
                ->where($model->qualifyColumn('responsible_user_id'), $user->getKey())
                ->orWhere($model->qualifyColumn('created_by'), $user->getKey())),
            AccessScope::Das => $query->whereIn($model->qualifyColumn('das_id'), UserDas::ids($user)),
        };
    }

    public static function allows(User $user, string $permission, Candidature $candidature): bool
    {
        return match (Scopes::of($user, $permission)) {
            null, AccessScope::Trainer => false,
            AccessScope::All => true,
            AccessScope::Own => in_array($user->getKey(), [$candidature->responsible_user_id, $candidature->created_by], true),
            AccessScope::Das => in_array($candidature->das_id, UserDas::ids($user), true),
        };
    }

    /**
     * $permission lets the user create (or move) a candidature into DAS
     * $dasId: created by him it is his (own); das: one he follows.
     */
    public static function allowsDas(User $user, string $permission, string $dasId): bool
    {
        return match (Scopes::of($user, $permission)) {
            null, AccessScope::Trainer => false,
            AccessScope::All, AccessScope::Own => true,
            AccessScope::Das => in_array($dasId, UserDas::ids($user), true),
        };
    }

    public static function sensitive(User $user): bool
    {
        return $user->can(Permissions::SENSITIVE_VIEW);
    }
}
