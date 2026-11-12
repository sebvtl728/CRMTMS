<?php

namespace App\Modules\Crm\Visibility;

use App\Modules\Core\Access\Role;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who sees which fiche. Everybody with crm.view sees everything in the
 * organisme, except the Responsable DAS who only sees what belongs to the
 * DAS he follows (table user_das, managed from the users page).
 */
final class CrmVisibility
{
    public static function restricts(User $user): bool
    {
        return $user->role() === Role::ResponsableDas;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function organisations(Builder $query, User $user): Builder
    {
        if (! self::restricts($user)) {
            return $query;
        }

        return $query->whereExists(fn ($sub) => $sub
            ->select(DB::raw(1))
            ->from('organisation_das')
            ->join('user_das', 'user_das.das_id', '=', 'organisation_das.das_id')
            ->whereColumn('organisation_das.organisation_id', $query->getModel()->qualifyColumn('id'))
            ->where('user_das.user_id', $user->getKey()));
    }

    /**
     * People linked (active affiliation) to a visible entreprise. Individuals
     * without entreprise follow the DAS rule defined with the people screens.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function people(Builder $query, User $user): Builder
    {
        if (! self::restricts($user)) {
            return $query;
        }

        return $query->whereExists(fn ($sub) => $sub
            ->select(DB::raw(1))
            ->from('affiliations')
            ->join('organisation_das', 'organisation_das.organisation_id', '=', 'affiliations.organisation_id')
            ->join('user_das', 'user_das.das_id', '=', 'organisation_das.das_id')
            ->whereColumn('affiliations.person_id', $query->getModel()->qualifyColumn('id'))
            ->whereNull('affiliations.ended_on')
            ->whereNull('affiliations.deleted_at')
            ->where('user_das.user_id', $user->getKey()));
    }

    /**
     * @return list<string> ids of the DAS followed by $user
     */
    public static function followedDas(User $user): array
    {
        return DB::table('user_das')->where('user_id', $user->getKey())->pluck('das_id')->all();
    }
}
