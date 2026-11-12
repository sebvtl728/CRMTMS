<?php

namespace App\Modules\Crm\Organisations;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Entreprises » screens: list with filters, fiche, create / edit form,
 * soft delete and restore.
 */
class OrganisationController extends Controller
{
    private const SORTS = ['name', 'city', 'last_contact_at', 'created_at'];

    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $canDelete = $user->can(Permissions::DELETE);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => $this->enumOrNull($request->query('status')),
            'das' => $this->idOrNull($request->query('das')),
            'sector' => $this->idOrNull($request->query('sector')),
            'responsible' => $this->idOrNull($request->query('responsible')),
            'origin' => $this->idOrNull($request->query('origin')),
            'mine' => $request->boolean('mine'),
            'trashed' => $canDelete && $request->boolean('trashed'),
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'name',
            'dir' => $request->query('dir') === 'desc' ? 'desc' : 'asc',
        ];

        $query = CrmVisibility::organisations(Organisation::query(), $user)
            ->with(['sector', 'responsible', 'das'])
            ->when($filters['trashed'], fn (Builder $q) => $q->onlyTrashed())
            ->when($filters['q'] !== '', fn (Builder $q) => $q->search($filters['q']))
            ->when($filters['status'], fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['sector'], fn (Builder $q, string $v) => $q->where('sector_id', $v))
            ->when($filters['origin'], fn (Builder $q, string $v) => $q->where('origin_id', $v))
            ->when($filters['responsible'], fn (Builder $q, string $v) => $q->where('responsible_user_id', $v))
            ->when($filters['mine'], fn (Builder $q) => $q->where('responsible_user_id', $user->getKey()))
            ->when($filters['das'], fn (Builder $q, string $v) => $q->whereIn('id', DB::table('organisation_das')->select('organisation_id')->where('das_id', $v)));

        if ($filters['sort'] === 'last_contact_at') {
            $query->orderByRaw('organisations.last_contact_at '.$filters['dir'].' nulls last');
        } else {
            $query->orderBy($filters['sort'], $filters['dir']);
        }
        $query->orderBy('name')->orderBy('id');

        return Inertia::render('crm/organisations/index', [
            'organisations' => $query->paginate(25)->withQueryString()->through(fn (Organisation $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'siret' => $o->siret,
                'city' => $o->city,
                'status' => ['value' => $o->status->value, 'label' => $o->status->label()],
                'sector' => $o->sector?->label,
                'responsible' => $o->responsible?->name,
                'das' => $o->das->pluck('label')->all(),
                'last_contact_at' => $o->last_contact_at?->toIso8601String(),
                'created_at' => $o->created_at?->toIso8601String(),
                'deleted' => $o->trashed(),
            ]),
            'filters' => $filters,
            'options' => [
                'status' => OrganisationStatus::options(),
                'das' => $this->options(ReferenceList::Das),
                'sector' => $this->options(ReferenceList::Sector),
                'origin' => $this->options(ReferenceList::ContactOrigin),
                'responsible' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(),
            ],
            'can' => ['create' => $user->can(Permissions::CREATE), 'delete' => $canDelete],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Organisation::class);

        return Inertia::render('crm/organisations/form', [
            'organisation' => null,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(OrganisationRequest $request): RedirectResponse
    {
        Gate::authorize('create', Organisation::class);

        $organisation = $this->persist(new Organisation, $request);

        return to_route('crm.organisations.show', $organisation)->with('status', 'Entreprise créée.');
    }

    public function show(Request $request, Organisation $organisation): Response
    {
        $user = $this->user($request);
        Gate::authorize('view', $organisation);

        $organisation->load(['legalForm', 'sector', 'establishmentType', 'companySize', 'origin', 'responsible', 'das']);
        $label = fn (?ReferenceItem $i) => $i === null ? null : ['id' => $i->id, 'label' => $i->label];

        return Inertia::render('crm/organisations/show', [
            'organisation' => [
                ...$this->fields($organisation),
                'status' => ['value' => $organisation->status->value, 'label' => $organisation->status->label()],
                'legal_form' => $label($organisation->legalForm),
                'sector' => $label($organisation->sector),
                'establishment_type' => $label($organisation->establishmentType),
                'company_size' => $label($organisation->companySize),
                'origin' => $label($organisation->origin),
                'responsible' => $organisation->responsible === null ? null : ['id' => $organisation->responsible->id, 'name' => $organisation->responsible->name],
                'das' => $organisation->das->map($label)->all(),
                'last_contact_at' => $organisation->last_contact_at?->toIso8601String(),
                'created_at' => $organisation->created_at?->toIso8601String(),
                'updated_at' => $organisation->updated_at?->toIso8601String(),
                'deleted' => $organisation->trashed(),
            ],
            'can' => [
                'update' => ! $organisation->trashed() && $user->can('update', $organisation),
                'delete' => ! $organisation->trashed() && $user->can('delete', $organisation),
                'restore' => $organisation->trashed() && $user->can('restore', $organisation),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function edit(Organisation $organisation): Response
    {
        Gate::authorize('update', $organisation);
        abort_if($organisation->trashed(), 404);

        return Inertia::render('crm/organisations/form', [
            'organisation' => [
                ...$this->fields($organisation),
                'status' => $organisation->status->value,
                'das_ids' => $organisation->das()->pluck('reference_items.id')->all(),
            ],
            'options' => $this->formOptions(),
        ]);
    }

    public function update(OrganisationRequest $request, Organisation $organisation): RedirectResponse
    {
        Gate::authorize('update', $organisation);
        abort_if($organisation->trashed(), 404);

        $this->persist($organisation, $request);

        return to_route('crm.organisations.show', $organisation)->with('status', 'Entreprise enregistrée.');
    }

    public function destroy(Organisation $organisation): RedirectResponse
    {
        Gate::authorize('delete', $organisation);
        abort_if($organisation->trashed(), 404);

        $organisation->delete();

        return to_route('crm.organisations.index')->with('status', 'Entreprise supprimée. Elle reste récupérable depuis la corbeille.');
    }

    public function restore(Request $request, Organisation $organisation): RedirectResponse
    {
        Gate::authorize('restore', $organisation);
        abort_unless($organisation->trashed(), 404);

        try {
            DB::transaction(fn () => $organisation->restore());
        } catch (UniqueConstraintViolationException) {
            // Another active entreprise took the SIRET in the meantime.
            $other = Organisation::query()->where('siret', $organisation->siret)->first();
            $errors = ['siret' => OrganisationRequest::DUPLICATE_SIRET];
            if ($other !== null && Gate::allows('view', $other)) {
                $errors['siret_existing_id'] = $other->id;
            }

            throw ValidationException::withMessages($errors)->redirectTo(route('crm.organisations.show', $organisation));
        }

        return to_route('crm.organisations.show', $organisation)->with('status', 'Entreprise restaurée.');
    }

    private function persist(Organisation $organisation, OrganisationRequest $request): Organisation
    {
        $validated = $request->validated();
        $dasIds = array_key_exists('das_ids', $validated) ? array_values($validated['das_ids']) : null;
        unset($validated['das_ids']);
        $validated['country'] ??= 'FR';
        $validated['status'] ??= $organisation->exists ? $organisation->status->value : OrganisationStatus::Prospect->value;

        try {
            DB::transaction(function () use ($organisation, $validated, $dasIds): void {
                $organisation->fill($validated)->save();

                if ($dasIds !== null) {
                    $this->syncDas($organisation, $dasIds);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race against another save of the same SIRET.
            $other = Organisation::query()->where('siret', $validated['siret'] ?? null)->whereKeyNot($organisation->getKey())->first();
            $errors = ['siret' => OrganisationRequest::DUPLICATE_SIRET];
            if ($other !== null && Gate::allows('view', $other)) {
                $errors['siret_existing_id'] = $other->id;
            }

            throw ValidationException::withMessages($errors);
        }

        return $organisation;
    }

    /**
     * @param  list<string>  $dasIds
     */
    private function syncDas(Organisation $organisation, array $dasIds): void
    {
        $before = $organisation->das()->pluck('reference_items.id')->all();
        $tenantId = $this->tenants->id();

        $organisation->das()->sync(collect($dasIds)->mapWithKeys(fn (string $id) => [$id => ['tenant_id' => $tenantId]])->all());

        sort($before);
        $after = $dasIds;
        sort($after);

        if ($before !== $after) {
            $this->audit->record('crm.organisation_das_changed', $organisation, ['das_ids' => $before], ['das_ids' => $after]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(Organisation $o): array
    {
        return [
            'id' => $o->id,
            'name' => $o->name,
            'siret' => $o->siret,
            'naf_code' => $o->naf_code,
            'legal_form_id' => $o->legal_form_id,
            'sector_id' => $o->sector_id,
            'establishment_type_id' => $o->establishment_type_id,
            'company_size_id' => $o->company_size_id,
            'origin_id' => $o->origin_id,
            'responsible_user_id' => $o->responsible_user_id,
            'email' => $o->email,
            'phone' => $o->phone,
            'website' => $o->website,
            'address_line1' => $o->address_line1,
            'address_line2' => $o->address_line2,
            'postal_code' => $o->postal_code,
            'city' => $o->city,
            'country' => $o->country,
            'notes_summary' => $o->notes_summary,
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function formOptions(): array
    {
        return [
            'status' => OrganisationStatus::options(),
            'legal_form' => $this->options(ReferenceList::LegalFormOrg),
            'sector' => $this->options(ReferenceList::Sector),
            'establishment_type' => $this->options(ReferenceList::EstablishmentType),
            'company_size' => $this->options(ReferenceList::CompanySize),
            'origin' => $this->options(ReferenceList::ContactOrigin),
            'das' => $this->options(ReferenceList::Das),
            'responsible' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function options(ReferenceList $list): array
    {
        return ReferenceItem::options($list)->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->label])->values()->all();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    private function enumOrNull(mixed $value): ?string
    {
        return OrganisationStatus::tryFrom((string) $value)?->value;
    }

    private function idOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
    }
}
