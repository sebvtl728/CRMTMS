<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The « Catalogue » list: filter reading, filter choices and query
 * building, shared by the list and the Excel export.
 */
final class TrainingListQuery
{
    public const SORTS = ['title', 'code', 'price_company'];

    public const STATUSES = ['active', 'inactive'];

    /**
     * Untrusted query input: anything that is not the expected scalar shape
     * becomes « no filter ».
     *
     * @param  array<array-key, mixed>  $input
     * @return array{q: string, das: ?string, status: ?string, funding: ?string, rncp: ?string, sort: string, dir: string}
     */
    public static function filters(array $input): array
    {
        $q = $input['q'] ?? null;

        return [
            'q' => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
            'das' => self::id($input['das'] ?? null),
            'status' => self::oneOf($input['status'] ?? null, self::STATUSES),
            'funding' => self::id($input['funding'] ?? null),
            // « alerte »: active trainings whose RNCP certification is unknown, inactive or ending soon.
            'rncp' => ($input['rncp'] ?? null) === 'alerte' ? 'alerte' : null,
            'sort' => self::oneOf($input['sort'] ?? null, self::SORTS) ?? 'title',
            'dir' => ($input['dir'] ?? null) === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * Choices of the filter bar.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        return [
            'das' => self::reference(ReferenceList::Das),
            'funding' => self::reference(ReferenceList::FundingMode),
            'status' => [['value' => 'active', 'label' => 'Actives'], ['value' => 'inactive', 'label' => 'Désactivées']],
            'sort' => [
                ['value' => 'title', 'label' => 'Intitulé'],
                ['value' => 'code', 'label' => 'Code'],
                ['value' => 'price_company', 'label' => 'Tarif entreprise'],
            ],
        ];
    }

    /**
     * @param  array{q: string, das: ?string, status: ?string, funding: ?string, rncp: ?string, sort: string, dir: string}  $filters
     * @return Builder<Training>
     */
    public static function build(array $filters): Builder
    {
        $query = self::search(Training::query(), $filters['q'])
            ->when($filters['das'], fn (Builder $q, string $v) => $q->where('trainings.das_id', $v))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('trainings.is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('trainings.is_active', false))
            ->when($filters['rncp'] === 'alerte', fn (Builder $q) => self::rncpAlerts($q))
            ->when($filters['funding'], fn (Builder $q, string $v) => $q->whereIn('trainings.id',
                DB::table('training_funding_modes')->select('training_id')->where('funding_mode_id', $v)));

        $column = $filters['sort'];
        $query->orderByRaw("trainings.{$column} {$filters['dir']} nulls last");

        return $query->orderBy('trainings.title')->orderBy('trainings.id');
    }

    /**
     * Every word in the code or the title (accent-insensitive). Applied to a
     * small table: no index needed (none usable under FORCE RLS anyway).
     *
     * @param  Builder<Training>  $query
     * @return Builder<Training>
     */
    public static function search(Builder $query, string $term): Builder
    {
        foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $pattern = '%'.addcslashes($word, '\\%_').'%';

            $query->where(fn (Builder $q) => $q
                ->whereRaw('public.immutable_unaccent(lower(trainings.title)) like public.immutable_unaccent(lower(?))', [$pattern])
                ->orWhereRaw('lower(trainings.code) like lower(?)', [$pattern]));
        }

        return $query;
    }

    /**
     * Active items, plus the given current ones even when inactive.
     *
     * @param  list<string>  $keep
     * @return list<array{value: string, label: string}>
     */
    public static function reference(ReferenceList $list, array $keep = []): array
    {
        return ReferenceItem::query()->where('list', $list)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $keep))
            ->orderBy('position')->orderBy('label')->get()
            ->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->is_active ? $i->label : $i->label.' (inactif)'])
            ->values()->all();
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Active trainings whose RNCP code needs attention (Rncp\RncpDirectory::alerts).
     *
     * @param  Builder<Training>  $query
     * @return Builder<Training>
     */
    public static function rncpAlerts(Builder $query): Builder
    {
        $codes = Training::query()->where('is_active', true)->whereNotNull('rncp_code')->distinct()->pluck('rncp_code')->map(fn ($c) => (string) $c)->all();
        $alerts = app(Rncp\RncpDirectory::class)->alerts($codes);

        return $query->where('trainings.is_active', true)->whereIn('trainings.rncp_code', $alerts === [] ? ['-'] : $alerts);
    }
}
