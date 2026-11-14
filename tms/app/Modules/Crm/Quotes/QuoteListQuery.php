<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Support\FilterInput;
use App\Modules\Crm\Support\FormOptions;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * The « Devis » list: filter reading, filter choices and query building.
 * Quotes of a trashed opportunity or fiche are left out, like them. The DAS
 * filter is the opportunity's (its principal DAS, as for visibility).
 */
final class QuoteListQuery
{
    /** Sort keys → columns. */
    public const SORTS = ['issued_on' => 'issued_on', 'number' => 'number', 'total' => 'total_ht'];

    /**
     * @param  array<array-key, mixed>  $input
     * @return array{q: string, status: ?string, company: ?string, das: ?string, responsible: ?string, issued_from: ?string, issued_to: ?string, sort: string, dir: string}
     */
    public static function filters(array $input): array
    {
        $sort = FilterInput::oneOf($input['sort'] ?? null, array_keys(self::SORTS)) ?? 'issued_on';

        return [
            'q' => FilterInput::text($input['q'] ?? null),
            'status' => FilterInput::oneOf($input['status'] ?? null, array_map(fn (QuoteStatus $s) => $s->value, QuoteStatus::cases())),
            'company' => FilterInput::id($input['company'] ?? null),
            'das' => FilterInput::id($input['das'] ?? null),
            'responsible' => FilterInput::id($input['responsible'] ?? null),
            'issued_from' => self::date($input['issued_from'] ?? null),
            'issued_to' => self::date($input['issued_to'] ?? null),
            'sort' => $sort,
            // Latest first by default; a number or a total in ascending order.
            'dir' => FilterInput::oneOf($input['dir'] ?? null, ['asc', 'desc']) ?? ($sort === 'issued_on' ? 'desc' : 'asc'),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function options(): array
    {
        return [
            'status' => QuoteStatus::options(),
            'company' => Company::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->name])->values()->all(),
            'das' => FormOptions::reference(ReferenceList::Das),
            'responsible' => FormOptions::responsible(),
            'sort' => [
                ['value' => 'issued_on', 'label' => 'Date'],
                ['value' => 'number', 'label' => 'Numéro'],
                ['value' => 'total', 'label' => 'Total HT'],
            ],
        ];
    }

    /**
     * @param  array{q: string, status: ?string, company: ?string, das: ?string, responsible: ?string, issued_from: ?string, issued_to: ?string, sort: string, dir: string}  $filters
     * @return Builder<Quote>
     */
    public static function build(array $filters, User $user): Builder
    {
        $opportunities = fn (): Builder => Opportunity::query()->select('opportunities.id')
            ->where(fn (Builder $q) => $q
                ->whereIn('opportunities.organisation_id', Organisation::query()->select('organisations.id'))
                ->orWhereIn('opportunities.person_id', Person::query()->select('people.id')));

        $query = CrmVisibility::quotes(Quote::query(), $user)
            ->whereIn('quotes.opportunity_id', $opportunities())
            ->when($filters['q'] !== '', fn (Builder $q) => self::search($q, $filters['q']))
            ->when($filters['status'], fn (Builder $q, string $v) => $q->where('quotes.status', $v))
            ->when($filters['company'], fn (Builder $q, string $v) => $q->where('quotes.company_id', $v))
            ->when($filters['das'], fn (Builder $q, string $v) => $q->whereIn('quotes.opportunity_id', $opportunities()->where('opportunities.das_id', $v)))
            ->when($filters['responsible'], fn (Builder $q, string $v) => $q->whereIn('quotes.opportunity_id', $opportunities()->where('opportunities.responsible_user_id', $v)))
            ->when($filters['issued_from'], fn (Builder $q, string $v) => $q->where('quotes.issued_on', '>=', $v))
            ->when($filters['issued_to'], fn (Builder $q, string $v) => $q->where('quotes.issued_on', '<=', $v));

        $column = self::SORTS[$filters['sort']];
        $dir = $filters['dir'];

        return $query->orderByRaw("quotes.{$column} {$dir}")->orderByDesc('quotes.number')->orderBy('quotes.id');
    }

    /**
     * Every word in the number, the title of the opportunity or the name of
     * the fiche (accent-insensitive).
     *
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    private static function search(Builder $query, string $term): Builder
    {
        foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $pattern = '%'.addcslashes($word, '\\%_').'%';

            $query->where(fn (Builder $q) => $q
                ->whereRaw('lower(quotes.number) like lower(?)', [$pattern])
                ->orWhereIn('quotes.opportunity_id', Opportunity::query()->select('opportunities.id')
                    ->whereRaw('public.immutable_unaccent(lower(opportunities.title)) like public.immutable_unaccent(lower(?))', [$pattern]))
                ->orWhereIn('quotes.organisation_id', Organisation::query()->select('organisations.id')
                    ->whereRaw('organisations.search_vector like public.immutable_unaccent(lower(?))', [$pattern]))
                ->orWhereIn('quotes.person_id', Person::query()->select('people.id')
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
