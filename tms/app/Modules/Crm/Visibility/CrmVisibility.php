<?php

namespace App\Modules\Crm\Visibility;

use App\Modules\Core\Access\Role;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
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

        // A link to a trashed entreprise is as good as none: it neither opens
        // the person nor hides her individual (person_das) rule.
        $activeAffiliations = fn () => DB::table('affiliations')
            ->select(DB::raw(1))
            ->join('organisations', 'organisations.id', '=', 'affiliations.organisation_id')
            ->whereColumn('affiliations.person_id', $personId)
            ->whereNull('affiliations.ended_on')
            ->whereNull('affiliations.deleted_at')
            ->whereNull('organisations.deleted_at');

        return $query->where(fn (Builder $q) => $q
            ->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('affiliations')
                ->join('organisation_das', 'organisation_das.organisation_id', '=', 'affiliations.organisation_id')
                ->join('user_das', 'user_das.das_id', '=', 'organisation_das.das_id')
                ->join('organisations', 'organisations.id', '=', 'affiliations.organisation_id')
                ->whereColumn('affiliations.person_id', $personId)
                ->whereNull('affiliations.ended_on')
                ->whereNull('affiliations.deleted_at')
                ->whereNull('organisations.deleted_at')
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
     * A Responsable DAS sees the opportunities whose DAS he follows (the DAS
     * of the opportunity, whatever the DAS of its fiche).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function opportunities(Builder $query, User $user): Builder
    {
        if (! self::restricts($user)) {
            return $query;
        }

        return $query->whereIn($query->getModel()->qualifyColumn('das_id'), DB::table('user_das')
            ->select('das_id')
            ->where('user_id', $user->getKey()));
    }

    /**
     * A quote is seen when its opportunity is (its DAS), whatever its lines.
     * Trashed opportunities and fiches are left to the caller.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function quotes(Builder $query, User $user): Builder
    {
        if (! self::restricts($user)) {
            return $query;
        }

        return $query->whereIn($query->getModel()->qualifyColumn('opportunity_id'), self::opportunities(Opportunity::withTrashed(), $user)->select('opportunities.id'));
    }

    /**
     * A Responsable DAS sees an action of an opportunity only when he sees
     * that opportunity (its DAS), whatever its fiches: the fiche of the
     * opportunity is stamped on every such action. An action without
     * opportunity is seen when its entreprise or its person is. Trashed
     * fiches are left to the caller (readable with crm.delete only).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function activities(Builder $query, User $user): Builder
    {
        if (! self::restricts($user)) {
            return $query;
        }

        $model = $query->getModel();
        $opportunity = $model->qualifyColumn('opportunity_id');

        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $o) => $o->whereNotNull($opportunity)
                ->whereIn($opportunity, self::opportunities(Opportunity::withTrashed(), $user)->select('opportunities.id')))
            ->orWhere(fn (Builder $f) => $f->whereNull($opportunity)
                ->where(fn (Builder $w) => $w
                    ->whereIn($model->qualifyColumn('organisation_id'), self::organisations(Organisation::withTrashed(), $user)->select('organisations.id'))
                    ->orWhereIn($model->qualifyColumn('person_id'), self::people(Person::withTrashed(), $user)->select('people.id')))));
    }

    /**
     * Eager loads of a person's active affiliations and their entreprise,
     * limited for a Responsable DAS to the entreprises he sees: a visible
     * person may also work elsewhere, outside his DAS (name, function, work
     * e-mail and phone of that link must not leak through lists, exports or
     * searches).
     *
     * @return array<int|string, mixed>
     */
    public static function activeAffiliationsWith(User $user): array
    {
        if (! self::restricts($user)) {
            return ['activeAffiliations.organisation'];
        }

        return [
            'activeAffiliations' => fn ($q) => $q->whereIn('affiliations.organisation_id', self::organisations(Organisation::query(), $user)->select('organisations.id')),
            'activeAffiliations.organisation',
        ];
    }

    /**
     * @return list<string> ids of the DAS followed by $user
     */
    public static function followedDas(User $user): array
    {
        return FollowedDas::ids($user);
    }
}
