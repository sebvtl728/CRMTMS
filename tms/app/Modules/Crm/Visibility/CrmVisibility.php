<?php

namespace App\Modules\Crm\Visibility;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\Quote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Who reaches which record: the scope (Scopes) of the requested permission.
 *
 *  - right not granted: nothing;
 *  - « Tout l'organisme » (all): everything in the organisme;
 *  - « Ses DAS » (das): what belongs to the DAS the user follows (table
 *    user_das, managed from the users page);
 *  - « Ses fiches » (own): what he is responsible for or created
 *    (responsible_user_id or created_by on the fiche, the opportunity or the
 *    action); a quote is his when its opportunity is, or when he created it;
 *    an action also when what it hangs on is his.
 *
 * A filter applied for another domain (the opportunity of a quote, the fiche
 * of an action) follows the same scope, not the user's right on that domain.
 */
final class CrmVisibility
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function organisations(Builder $query, User $user, string $permission = Permissions::FICHES_VIEW): Builder
    {
        return self::organisationsIn($query, $user, Scopes::of($user, $permission));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function people(Builder $query, User $user, string $permission = Permissions::FICHES_VIEW): Builder
    {
        return self::peopleIn($query, $user, Scopes::of($user, $permission));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function opportunities(Builder $query, User $user, string $permission = Permissions::OPPORTUNITIES_VIEW): Builder
    {
        return self::opportunitiesIn($query, $user, Scopes::of($user, $permission));
    }

    /**
     * Trashed opportunities and fiches are left to the caller.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function quotes(Builder $query, User $user, string $permission = Permissions::QUOTES_VIEW): Builder
    {
        return self::quotesIn($query, $user, Scopes::of($user, $permission));
    }

    /**
     * Trashed fiches are left to the caller (readable with crm.fiches.delete only).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function activities(Builder $query, User $user, string $permission = Permissions::ACTIVITIES_VIEW): Builder
    {
        return self::activitiesIn($query, $user, Scopes::of($user, $permission), self::responsibleReaches($permission));
    }

    /**
     * $permission reaches $record (an entreprise, a person, an opportunity, a
     * quote or an action, trashed ones too): the record is in the filtered query.
     */
    public static function allows(User $user, string $permission, Model $record): bool
    {
        $scope = Scopes::of($user, $permission);
        if ($scope === null) {
            return false;
        }
        if ($scope === AccessScope::All) {
            return true;
        }

        return self::scoped($record::class, $user, $scope, $permission)->whereKey($record->getKey())->exists();
    }

    /**
     * $permission (crm.quotes.update) reaches a new quote on $opportunity:
     * created by the user, it is his (Own); Das: the opportunity's DAS.
     */
    public static function quotable(User $user, string $permission, Opportunity $opportunity): bool
    {
        $scope = Scopes::of($user, $permission);

        return match ($scope) {
            null => false,
            AccessScope::All, AccessScope::Own => true,
            AccessScope::Das => self::opportunitiesIn(Opportunity::withTrashed(), $user, $scope)->whereKey($opportunity->getKey())->exists(),
        };
    }

    /**
     * Ids among $records that $permission reaches, in one query (rights of
     * each row of a list: kanban cards, « À faire », planning, historique).
     *
     * @param  Collection<int, covariant Model>  $records  of a single class
     * @return array<string, true> id => true
     */
    public static function allowedIds(User $user, string $permission, Collection $records): array
    {
        $first = $records->first();
        $scope = Scopes::of($user, $permission);
        if ($first === null || $scope === null) {
            return [];
        }
        $ids = $records->map(fn (Model $r) => (string) $r->getKey())->all();
        if ($scope !== AccessScope::All) {
            $ids = self::scoped($first::class, $user, $scope, $permission)->whereKey($ids)->pluck($first->getKeyName())->map(fn ($id) => (string) $id)->all();
        }

        return array_fill_keys($ids, true);
    }

    /**
     * Whether being responsible for an action reaches it under the Das scope
     * of $permission: view and update (« Confier à… »), not delete.
     */
    private static function responsibleReaches(string $permission): bool
    {
        return $permission !== Permissions::ACTIVITIES_DELETE;
    }

    /**
     * Eager loads of a person's active affiliations and their entreprise,
     * limited to the entreprises the user sees when he does not see them
     * all: a visible person may also work elsewhere (name, function, work
     * e-mail and phone of that link must not leak through lists, exports or
     * searches).
     *
     * @return array<int|string, mixed>
     */
    public static function activeAffiliationsWith(User $user): array
    {
        if (Scopes::of($user, Permissions::FICHES_VIEW) === AccessScope::All) {
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

    /**
     * @param  class-string<Model>  $class
     * @return Builder<covariant Model>
     */
    private static function scoped(string $class, User $user, AccessScope $scope, string $permission): Builder
    {
        return match ($class) {
            Organisation::class => self::organisationsIn(Organisation::withTrashed(), $user, $scope),
            Person::class => self::peopleIn(Person::withTrashed(), $user, $scope),
            Opportunity::class => self::opportunitiesIn(Opportunity::withTrashed(), $user, $scope),
            Quote::class => self::quotesIn(Quote::withTrashed(), $user, $scope),
            Activity::class => self::activitiesIn(Activity::withTrashed(), $user, $scope, self::responsibleReaches($permission)),
            default => throw new InvalidArgumentException("No visibility rule for {$class}."),
        };
    }

    /**
     * Responsible for it or created it.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function own(Builder $query, User $user): Builder
    {
        $model = $query->getModel();

        return $query->where(fn (Builder $q) => $q
            ->where($model->qualifyColumn('responsible_user_id'), $user->getKey())
            ->orWhere($model->qualifyColumn('created_by'), $user->getKey()));
    }

    /**
     * Das: an entreprise holding one of the DAS he follows.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function organisationsIn(Builder $query, User $user, ?AccessScope $scope): Builder
    {
        return match ($scope) {
            null => $query->whereRaw('false'),
            AccessScope::All => $query,
            AccessScope::Own => self::own($query, $user),
            AccessScope::Das => $query->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('organisation_das')
                ->join('user_das', 'user_das.das_id', '=', 'organisation_das.das_id')
                ->whereColumn('organisation_das.organisation_id', $query->getModel()->qualifyColumn('id'))
                ->where('user_das.user_id', $user->getKey())),
        };
    }

    /**
     * Das: a person with an active affiliation to a visible entreprise, or
     * without any active affiliation (individual, former contact) sharing
     * one of the DAS he follows (table person_das). An active affiliation
     * elsewhere hides her even when her own DAS matches: she belongs to that
     * other entreprise.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function peopleIn(Builder $query, User $user, ?AccessScope $scope): Builder
    {
        if ($scope !== AccessScope::Das) {
            return match ($scope) {
                null => $query->whereRaw('false'),
                AccessScope::All => $query,
                AccessScope::Own => self::own($query, $user),
            };
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
     * Das: the opportunities whose DAS he follows (the DAS of the
     * opportunity, whatever the DAS of its fiche).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function opportunitiesIn(Builder $query, User $user, ?AccessScope $scope): Builder
    {
        return match ($scope) {
            null => $query->whereRaw('false'),
            AccessScope::All => $query,
            AccessScope::Own => self::own($query, $user),
            AccessScope::Das => $query->whereIn($query->getModel()->qualifyColumn('das_id'), DB::table('user_das')
                ->select('das_id')
                ->where('user_id', $user->getKey())),
        };
    }

    /**
     * A quote follows its opportunity (Das: its DAS, whatever its lines);
     * Own: also the quotes he created.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function quotesIn(Builder $query, User $user, ?AccessScope $scope): Builder
    {
        if ($scope === null || $scope === AccessScope::All) {
            return $scope === null ? $query->whereRaw('false') : $query;
        }

        $model = $query->getModel();
        $opportunities = self::opportunitiesIn(Opportunity::withTrashed(), $user, $scope)->select('opportunities.id');

        return $scope === AccessScope::Das
            ? $query->whereIn($model->qualifyColumn('opportunity_id'), $opportunities)
            : $query->where(fn (Builder $q) => $q
                ->whereIn($model->qualifyColumn('opportunity_id'), $opportunities)
                ->orWhere($model->qualifyColumn('created_by'), $user->getKey()));
    }

    /**
     * An action of an opportunity follows that opportunity (the fiche of the
     * opportunity is stamped on every such action); an action without
     * opportunity follows its entreprise or its person. Own: also the
     * actions he is responsible for or created; Das: also those he is
     * responsible for, to see, do and edit them, never to remove them
     * (crm.activities.delete stays on his DAS).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function activitiesIn(Builder $query, User $user, ?AccessScope $scope, bool $asResponsible = true): Builder
    {
        if ($scope === null || $scope === AccessScope::All) {
            return $scope === null ? $query->whereRaw('false') : $query;
        }

        $model = $query->getModel();
        $opportunity = $model->qualifyColumn('opportunity_id');
        $hangsOn = fn (Builder $q) => $q
            ->where(fn (Builder $o) => $o->whereNotNull($opportunity)
                ->whereIn($opportunity, self::opportunitiesIn(Opportunity::withTrashed(), $user, $scope)->select('opportunities.id')))
            ->orWhere(fn (Builder $f) => $f->whereNull($opportunity)
                ->where(fn (Builder $w) => $w
                    ->whereIn($model->qualifyColumn('organisation_id'), self::organisationsIn(Organisation::withTrashed(), $user, $scope)->select('organisations.id'))
                    ->orWhereIn($model->qualifyColumn('person_id'), self::peopleIn(Person::withTrashed(), $user, $scope)->select('people.id'))));

        // The responsible always sees (and, with the right, does) his action,
        // even on a fiche he may not open (« Confier à… »).
        if ($scope === AccessScope::Das) {
            return $asResponsible
                ? $query->where(fn (Builder $q) => $q
                    ->where($model->qualifyColumn('responsible_user_id'), $user->getKey())
                    ->orWhere($hangsOn))
                : $query->where($hangsOn);
        }

        return $query
            ->where(fn (Builder $q) => $q
                ->where($model->qualifyColumn('responsible_user_id'), $user->getKey())
                ->orWhere($model->qualifyColumn('created_by'), $user->getKey())
                ->orWhere($hangsOn));
    }
}
