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
     * A Responsable DAS sees a person when she has an active affiliation to
     * a visible entreprise, or when she has no active affiliation at all
     * (individual, former contact) and shares one of the DAS he follows
     * (table person_das). An active affiliation elsewhere hides her even when
     * her own DAS matches: she belongs to that other entreprise.
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

        $personId = $query->getModel()->qualifyColumn('id');

        $activeAffiliations = fn () => DB::table('affiliations')
            ->select(DB::raw(1))
            ->whereColumn('affiliations.person_id', $personId)
            ->whereNull('affiliations.ended_on')
            ->whereNull('affiliations.deleted_at');

        return $query->where(fn (Builder $q) => $q
            ->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('affiliations')
                ->join('organisation_das', 'organisation_das.organisation_id', '=', 'affiliations.organisation_id')
                ->join('user_das', 'user_das.das_id', '=', 'organisation_das.das_id')
                ->whereColumn('affiliations.person_id', $personId)
                ->whereNull('affiliations.ended_on')
                ->whereNull('affiliations.deleted_at')
                ->where('user_das.user_id', $user->getKey()))
            ->orWhere(fn (Builder $unaffiliated) => $unaffiliated
                ->whereNotExists($activeAffiliations())
                ->whereExists(fn ($sub) => $sub
                    ->select(DB::raw(1))
                    ->from('person_das')
                    ->join('user_das', 'user_das.das_id', '=', 'person_das.das_id')
                    ->whereColumn('person_das.person_id', $personId)
                    ->where('user_das.user_id', $user->getKey()))));
    }

    /**
     * @return list<string> ids of the DAS followed by $user
     */
    public static function followedDas(User $user): array
    {
        return DB::table('user_das')->where('user_id', $user->getKey())->pluck('das_id')->all();
    }
}
