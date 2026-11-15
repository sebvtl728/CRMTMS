<?php

namespace App\Modules\Crm\People;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Interests\FicheTrainings;
use App\Modules\Crm\Organisations\OrganisationStatus;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Support\FilterInput;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The « Personnes » list: filter reading and query building, shared by the
 * page and the Excel export so that both show exactly the same fiches.
 */
final class PersonListQuery
{
    public const SORTS = ['name', 'city', 'last_contact_at', 'created_at'];

    public const TYPES = ['individual', 'contact'];

    /** Filters a saved filter may hold (trashed is a view, never saved). */
    public const SAVABLE = ['q', 'type', 'status', 'situation', 'funding', 'origin', 'responsible', 'training', 'mine', 'sort', 'dir'];

    /**
     * @param  array<array-key, mixed>  $input  query string or saved filter
     * @return array{q: string, type: ?string, status: ?string, situation: ?string, funding: ?string, origin: ?string, responsible: ?string, training: ?string, mine: bool, trashed: bool, sort: string, dir: string}
     */
    public static function filters(array $input, User $user): array
    {
        return [
            'q' => FilterInput::text($input['q'] ?? null),
            'type' => FilterInput::oneOf($input['type'] ?? null, self::TYPES),
            'status' => FilterInput::oneOf($input['status'] ?? null, array_column(OrganisationStatus::cases(), 'value')),
            'situation' => FilterInput::id($input['situation'] ?? null),
            'funding' => FilterInput::id($input['funding'] ?? null),
            'origin' => FilterInput::id($input['origin'] ?? null),
            'responsible' => FilterInput::id($input['responsible'] ?? null),
            'training' => FilterInput::id($input['training'] ?? null),
            'mine' => FilterInput::bool($input['mine'] ?? false),
            'trashed' => $user->can(Permissions::FICHES_DELETE) && FilterInput::bool($input['trashed'] ?? false),
            'sort' => FilterInput::oneOf($input['sort'] ?? null, self::SORTS) ?? 'name',
            'dir' => ($input['dir'] ?? null) === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * Validation rules of the savable filters (saved filter requests).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'q' => ['string', 'max:100'],
            'type' => [Rule::in(self::TYPES)],
            'status' => [Rule::in(array_column(OrganisationStatus::cases(), 'value'))],
            'situation' => ['uuid'],
            'funding' => ['uuid'],
            'origin' => ['uuid'],
            'responsible' => ['uuid'],
            'training' => ['uuid'],
            'mine' => ['boolean'],
            'sort' => [Rule::in(self::SORTS)],
            'dir' => [Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @param  array{q: string, type: ?string, status: ?string, situation: ?string, funding: ?string, origin: ?string, responsible: ?string, training: ?string, mine: bool, trashed: bool, sort: string, dir: string}  $filters
     * @return Builder<Person>
     */
    public static function build(array $filters, User $user): Builder
    {
        $query = CrmVisibility::people(Person::query(), $user)
            ->when($filters['trashed'], fn (Builder $q) => $q->onlyTrashed()->whereNull('merged_into_id'))
            ->when($filters['q'] !== '', fn (Builder $q) => $q->search($filters['q']))
            ->when($filters['type'] === 'individual', fn (Builder $q) => $q->where('is_individual', true))
            ->when($filters['type'] === 'contact', fn (Builder $q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('affiliations')
                ->whereColumn('affiliations.person_id', 'people.id')->whereNull('affiliations.ended_on')->whereNull('affiliations.deleted_at')))
            ->when($filters['status'], fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['situation'], fn (Builder $q, string $v) => $q->where('professional_situation_id', $v))
            ->when($filters['funding'], fn (Builder $q, string $v) => $q->where('funding_mode_id', $v))
            ->when($filters['origin'], fn (Builder $q, string $v) => $q->where('origin_id', $v))
            ->when($filters['responsible'], fn (Builder $q, string $v) => $q->where('responsible_user_id', $v))
            ->when($filters['training'], fn (Builder $q, string $v) => FicheTrainings::filter($q, $v, 'people'))
            ->when($filters['mine'], fn (Builder $q) => $q->where('responsible_user_id', $user->getKey()));

        $dir = $filters['dir'];
        match ($filters['sort']) {
            'last_contact_at' => $query->orderByRaw("people.last_contact_at {$dir} nulls last"),
            'name' => $query->orderBy('last_name', $dir)->orderBy('first_name', $dir),
            default => $query->orderBy($filters['sort'], $dir),
        };

        return $query->orderBy('last_name')->orderBy('first_name')->orderBy('id');
    }
}
