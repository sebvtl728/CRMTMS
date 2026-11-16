<?php

namespace App\Modules\Sessions\Dossiers;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionListQuery;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * The « Dossiers » list and its export: filter reading, filter choices and
 * the one query (view scope of sessions.dossiers.view; the trash only with
 * sessions.dossiers.delete). The search covers the number, the contractant
 * (entreprise or particulier, through Crm\Public) and the trainings of the
 * lines. The state filter reads the stored state (the sessions of the
 * enrolments, task 5, will add the computed « En cours » / « Réalisé »).
 *
 * @phpstan-type Filters array{q: string, status: ?string, das: ?string, responsible: ?string, trashed: bool, sort: string, dir: string}
 */
final class DossierListQuery
{
    /** Sort keys → columns. */
    public const SORTS = ['created_at' => 'created_at', 'number' => 'number'];

    /**
     * @param  array<array-key, mixed>  $input
     * @return Filters
     */
    public static function filters(array $input): array
    {
        $sort = self::oneOf($input['sort'] ?? null, array_keys(self::SORTS)) ?? 'created_at';
        $session = SessionListQuery::filters(['das' => $input['das'] ?? null, 'responsible' => $input['responsible'] ?? null]);

        return [
            'q' => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 100) : '',
            'status' => self::oneOf($input['status'] ?? null, array_map(fn (DossierStatus $s) => $s->value, DossierStatus::OFFERED)),
            'das' => $session['das'],
            'responsible' => $session['responsible'],
            'trashed' => filter_var($input['trashed'] ?? false, FILTER_VALIDATE_BOOL),
            'sort' => $sort,
            // Latest first by default; numbers in ascending order.
            'dir' => self::oneOf($input['dir'] ?? null, ['asc', 'desc']) ?? ($sort === 'created_at' ? 'desc' : 'asc'),
        ];
    }

    /**
     * @param  Filters  $filters
     * @return Builder<Dossier>
     */
    public static function build(array $filters, User $user): Builder
    {
        $query = SessionsVisibility::dossiers(Dossier::query(), $user);
        if ($filters['trashed'] && $user->can(Permissions::DOSSIERS_DELETE)) {
            $query->onlyTrashed();
        }

        $q = $filters['q'];
        $query
            ->when($q !== '', function (Builder $b) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $organisations = array_column(app(Fiches::class)->searchOrganisations($q, 50), 'id');
                $people = array_column(app(Trainees::class)->search($q, null, 50), 'id');
                $trainings = array_map(fn ($t) => $t->id, app(Trainings::class)->search($q, null, 100));
                $b->where(fn (Builder $w) => $w
                    ->where('dossiers.number', 'ilike', $like)
                    ->when($organisations !== [], fn (Builder $o) => $o->orWhereIn('dossiers.account_organisation_id', $organisations))
                    ->when($people !== [], fn (Builder $p) => $p->orWhereIn('dossiers.account_person_id', $people))
                    ->when($trainings !== [], fn (Builder $t) => $t->orWhereHas('lines', fn (Builder $l) => $l->whereIn('dossier_lines.training_id', $trainings))));
            })
            ->when($filters['status'], fn (Builder $b, string $status) => $b->where('dossiers.status', $status))
            ->when($filters['das'], fn (Builder $b, string $id) => $b->where('dossiers.das_id', $id))
            ->when($filters['responsible'], fn (Builder $b, string $id) => $b->where('dossiers.responsible_user_id', $id));

        return $query->orderBy('dossiers.'.self::SORTS[$filters['sort']], $filters['dir'])
            ->orderBy('dossiers.number', $filters['dir']);
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        return [
            'status' => DossierStatus::options(),
            'das' => SessionListQuery::reference(ReferenceList::Das),
            'responsible' => SessionListQuery::responsible(),
            'sort' => [
                ['value' => 'created_at', 'label' => 'Date de création'],
                ['value' => 'number', 'label' => 'Numéro'],
            ],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
