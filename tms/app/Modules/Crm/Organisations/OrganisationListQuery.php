<?php

namespace App\Modules\Crm\Organisations;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Interests\FicheTrainings;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Support\FilterInput;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The « Entreprises » list: filter reading and query building, shared by the
 * page and the Excel export so that both show exactly the same fiches.
 */
final class OrganisationListQuery
{
    public const SORTS = ['name', 'city', 'last_contact_at', 'created_at'];

    /** Filters a saved filter may hold (trashed is a view, never saved). */
    public const SAVABLE = ['q', 'status', 'das', 'type', 'sector', 'responsible', 'origin', 'training', 'mine', 'sort', 'dir'];

    /**
     * @param  array<array-key, mixed>  $input  query string or saved filter
     * @return array{q: string, status: ?string, das: ?string, type: ?string, sector: ?string, responsible: ?string, origin: ?string, training: ?string, mine: bool, trashed: bool, sort: string, dir: string}
     */
    public static function filters(array $input, User $user): array
    {
        return [
            'q' => FilterInput::text($input['q'] ?? null),
            'status' => FilterInput::oneOf($input['status'] ?? null, array_column(OrganisationStatus::cases(), 'value')),
            'das' => FilterInput::id($input['das'] ?? null),
            'type' => FilterInput::id($input['type'] ?? null),
            'sector' => FilterInput::id($input['sector'] ?? null),
            'responsible' => FilterInput::id($input['responsible'] ?? null),
            'training' => FilterInput::id($input['training'] ?? null),
            'origin' => FilterInput::id($input['origin'] ?? null),
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
            'status' => [Rule::in(array_column(OrganisationStatus::cases(), 'value'))],
            'das' => ['uuid'],
            'type' => ['uuid'],
            'sector' => ['uuid'],
            'responsible' => ['uuid'],
            'training' => ['uuid'],
            'origin' => ['uuid'],
            'mine' => ['boolean'],
            'sort' => [Rule::in(self::SORTS)],
            'dir' => [Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @param  array{q: string, status: ?string, das: ?string, type: ?string, sector: ?string, responsible: ?string, origin: ?string, training: ?string, mine: bool, trashed: bool, sort: string, dir: string}  $filters
     * @return Builder<Organisation>
     */
    public static function build(array $filters, User $user): Builder
    {
        $query = CrmVisibility::organisations(Organisation::query(), $user)
            ->when($filters['trashed'], fn (Builder $q) => $q->onlyTrashed()->whereNull('merged_into_id'))
            ->when($filters['q'] !== '', fn (Builder $q) => $q->search($filters['q']))
            ->when($filters['status'], fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['sector'], fn (Builder $q, string $v) => $q->where('sector_id', $v))
            ->when($filters['origin'], fn (Builder $q, string $v) => $q->where('origin_id', $v))
            ->when($filters['responsible'], fn (Builder $q, string $v) => $q->where('responsible_user_id', $v))
            ->when($filters['training'], fn (Builder $q, string $v) => FicheTrainings::filter($q, $v, 'organisations'))
            ->when($filters['mine'], fn (Builder $q) => $q->where('responsible_user_id', $user->getKey()))
            ->when($filters['das'], fn (Builder $q, string $v) => $q->whereIn('id', DB::table('organisation_das')->select('organisation_id')->where('das_id', $v)))
            ->when($filters['type'], fn (Builder $q, string $v) => $q->whereIn('id', DB::table('organisation_types')->select('organisation_id')->where('type_id', $v)));

        if ($filters['sort'] === 'last_contact_at') {
            $query->orderByRaw('organisations.last_contact_at '.$filters['dir'].' nulls last');
        } else {
            $query->orderBy($filters['sort'], $filters['dir']);
        }

        return $query->orderBy('name')->orderBy('id');
    }
}
