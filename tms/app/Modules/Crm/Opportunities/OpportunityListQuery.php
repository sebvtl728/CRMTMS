<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Support\FilterInput;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * The « Opportunités » list: filter reading and query building (the kanban
 * and the export of Task 4 reuse it). Opportunities of a trashed fiche are
 * left out, like the fiche itself.
 */
final class OpportunityListQuery
{
    public const SORTS = ['expected_close_on', 'amount', 'weighted_amount', 'created_at'];

    public const TARGETS = ['organisation', 'individual'];

    /**
     * @param  array<array-key, mixed>  $input
     * @return array{q: string, das: ?string, target: ?string, responsible: ?string, priority: ?string, stage: ?string, close_from: ?string, close_to: ?string, mine: bool, sort: string, dir: string}
     */
    public static function filters(array $input): array
    {
        return [
            'q' => FilterInput::text($input['q'] ?? null),
            'das' => FilterInput::id($input['das'] ?? null),
            'target' => FilterInput::oneOf($input['target'] ?? null, self::TARGETS),
            'responsible' => FilterInput::id($input['responsible'] ?? null),
            'priority' => FilterInput::id($input['priority'] ?? null),
            'stage' => FilterInput::id($input['stage'] ?? null),
            'close_from' => self::date($input['close_from'] ?? null),
            'close_to' => self::date($input['close_to'] ?? null),
            'mine' => FilterInput::bool($input['mine'] ?? false),
            'sort' => FilterInput::oneOf($input['sort'] ?? null, self::SORTS) ?? 'expected_close_on',
            'dir' => ($input['dir'] ?? null) === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array{q: string, das: ?string, target: ?string, responsible: ?string, priority: ?string, stage: ?string, close_from: ?string, close_to: ?string, mine: bool, sort: string, dir: string}  $filters
     * @return Builder<Opportunity>
     */
    public static function build(array $filters, User $user): Builder
    {
        $query = CrmVisibility::opportunities(Opportunity::query(), $user)
            ->where(fn (Builder $q) => $q
                ->whereIn('opportunities.organisation_id', Organisation::query()->select('organisations.id'))
                ->orWhereIn('opportunities.person_id', Person::query()->select('people.id')))
            ->when($filters['q'] !== '', fn (Builder $q) => self::search($q, $filters['q']))
            ->when($filters['das'], fn (Builder $q, string $v) => $q->where('opportunities.das_id', $v))
            ->when($filters['target'] === 'organisation', fn (Builder $q) => $q->whereNotNull('opportunities.organisation_id'))
            ->when($filters['target'] === 'individual', fn (Builder $q) => $q->whereNotNull('opportunities.person_id'))
            ->when($filters['responsible'], fn (Builder $q, string $v) => $q->where('opportunities.responsible_user_id', $v))
            ->when($filters['priority'], fn (Builder $q, string $v) => $q->where('opportunities.priority_id', $v))
            ->when($filters['stage'], fn (Builder $q, string $v) => $q->where('opportunities.stage_id', $v))
            ->when($filters['close_from'], fn (Builder $q, string $v) => $q->where('opportunities.expected_close_on', '>=', $v))
            ->when($filters['close_to'], fn (Builder $q, string $v) => $q->where('opportunities.expected_close_on', '<=', $v))
            ->when($filters['mine'], fn (Builder $q) => $q->where('opportunities.responsible_user_id', $user->getKey()));

        $dir = $filters['dir'];
        $column = $filters['sort'];
        $query->orderByRaw($column === 'created_at' ? "opportunities.created_at {$dir}" : "opportunities.{$column} {$dir} nulls last");

        return $query->orderByDesc('opportunities.created_at')->orderBy('opportunities.id');
    }

    /**
     * Every word in the title or the name of the fiche (accent-insensitive).
     *
     * @param  Builder<Opportunity>  $query
     * @return Builder<Opportunity>
     */
    private static function search(Builder $query, string $term): Builder
    {
        foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $pattern = '%'.addcslashes($word, '\\%_').'%';

            $query->where(fn (Builder $q) => $q
                ->whereRaw('public.immutable_unaccent(lower(opportunities.title)) like public.immutable_unaccent(lower(?))', [$pattern])
                ->orWhereIn('opportunities.organisation_id', Organisation::query()->select('organisations.id')
                    ->whereRaw('organisations.search_vector like public.immutable_unaccent(lower(?))', [$pattern]))
                ->orWhereIn('opportunities.person_id', Person::query()->select('people.id')
                    ->whereRaw('people.search_vector like public.immutable_unaccent(lower(?))', [$pattern])));
        }

        return $query;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
}
