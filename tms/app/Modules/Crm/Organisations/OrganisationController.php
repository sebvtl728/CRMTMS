<?php

namespace App\Modules\Crm\Organisations;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Duplicates\DuplicateGuard;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Search\CrmResource;
use App\Modules\Crm\Search\ListExtras;
use App\Modules\Crm\Support\FormOptions;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * « Entreprises » screens: list with filters, fiche, create / edit form,
 * soft delete and restore.
 */
class OrganisationController extends Controller
{
    private const RESTORE_CONFLICT = 'Impossible de restaurer cette entreprise : elle entre en conflit avec une autre fiche active.';

    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit, private readonly DuplicateGuard $duplicates) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $canDelete = $user->can(Permissions::DELETE);

        $filters = OrganisationListQuery::filters($request->query(), $user);
        $query = OrganisationListQuery::build($filters, $user)->with(CrmResource::Organisations->export()->with());

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
                'cells' => $this->cells(CrmResource::Organisations, $o),
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
            'can' => ['create' => $user->can(Permissions::CREATE), 'delete' => $canDelete, 'export' => $user->can(Permissions::EXPORT)],
            ...ListExtras::for(CrmResource::Organisations, $user, $request->query('saved')),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Organisation::class);

        return Inertia::render('crm/organisations/form', [
            'organisation' => null,
            'options' => $this->formOptions(null),
            'duplicates' => DuplicateGuard::flashed($request),
            'canMerge' => $this->user($request)->can(Permissions::MERGE),
        ]);
    }

    public function store(OrganisationRequest $request): HttpResponse
    {
        Gate::authorize('create', Organisation::class);

        $duplicate = $this->duplicates->organisation($request, $request->validated(), null, route('crm.organisations.create'));
        if ($duplicate !== null) {
            return $duplicate;
        }

        $organisation = $this->persist(new Organisation, $request);

        // « Fusionner » from the warning: created, then straight to the merge screen.
        $mergeInto = $request->input('merge_into');
        if (is_string($mergeInto) && $this->user($request)->can(Permissions::MERGE)
            && CrmVisibility::organisations(Organisation::query(), $this->user($request))->whereKey($this->idOrNull($mergeInto))->exists()) {
            return to_route('crm.merge.show', ['type' => 'organisation', 'keep' => $mergeInto, 'drop' => $organisation->id]);
        }

        return to_route('crm.organisations.show', $organisation)->with('status', 'Entreprise créée.');
    }

    public function show(Request $request, Organisation $organisation): Response
    {
        $user = $this->user($request);
        Gate::authorize('view', $organisation);

        $organisation->load(['legalForm', 'sector', 'establishmentType', 'companySize', 'origin', 'responsible', 'das']);
        $label = fn (?ReferenceItem $i) => $i === null ? null : ['id' => $i->id, 'label' => $i->label];

        // Interlocuteurs: referents first, then by name. Ended ones apart.
        $affiliations = $organisation->affiliations()->with(['person', 'decisionRole'])
            ->whereHas('person')
            ->get()
            ->sortBy(fn (Affiliation $a) => [$a->is_referent ? 0 : 1, mb_strtolower((string) $a->person?->last_name), mb_strtolower((string) $a->person?->first_name)])
            ->values();
        $affiliationRow = fn (Affiliation $a) => [
            'id' => $a->id,
            'person' => ['id' => $a->person_id, 'name' => $a->person?->fullName(), 'civility' => $a->person?->civility],
            'job_title' => $a->job_title,
            'decision_role_id' => $a->decision_role_id,
            'decision_role' => $label($a->decisionRole),
            'work_email' => $a->work_email,
            'work_phone' => $a->work_phone,
            'is_referent' => $a->is_referent,
            'started_on' => $a->started_on?->toDateString(),
            'ended_on' => $a->ended_on?->toDateString(),
        ];

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
            'affiliations' => $affiliations->whereNull('ended_on')->map($affiliationRow)->values()->all(),
            'formerAffiliations' => $affiliations->whereNotNull('ended_on')->sortByDesc(fn (Affiliation $a) => $a->ended_on)->map($affiliationRow)->values()->all(),
            'decisionRoles' => FormOptions::reference(ReferenceList::DecisionRole),
            'similar' => $user->can(Permissions::MERGE) ? $this->duplicates->similarTo($organisation, $user) : [],
            'can' => [
                'merge' => ! $organisation->trashed() && $user->can(Permissions::MERGE),
                'affiliate' => ! $organisation->trashed() && $user->can('update', $organisation),
                'createPerson' => $user->can(Permissions::CREATE),
                'update' => ! $organisation->trashed() && $user->can('update', $organisation),
                'delete' => ! $organisation->trashed() && $user->can('delete', $organisation),
                'restore' => $organisation->trashed() && $user->can('restore', $organisation),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function edit(Request $request, Organisation $organisation): Response
    {
        Gate::authorize('update', $organisation);
        abort_if($organisation->trashed(), 404);

        return Inertia::render('crm/organisations/form', [
            'organisation' => [
                ...$this->fields($organisation),
                'status' => $organisation->status->value,
                'das_ids' => $organisation->das()->pluck('reference_items.id')->all(),
            ],
            'options' => $this->formOptions($organisation),
            'duplicates' => DuplicateGuard::flashed($request),
            'canMerge' => $this->user($request)->can(Permissions::MERGE),
        ]);
    }

    public function update(OrganisationRequest $request, Organisation $organisation): HttpResponse
    {
        Gate::authorize('update', $organisation);
        abort_if($organisation->trashed(), 404);

        $duplicate = $this->duplicates->organisation($request, $request->validated(), $organisation, route('crm.organisations.edit', $organisation));
        if ($duplicate !== null) {
            return $duplicate;
        }

        $this->persist($organisation, $request);

        return to_route('crm.organisations.show', $organisation)->with('status', 'Entreprise enregistrée.');
    }

    public function destroy(Organisation $organisation): RedirectResponse
    {
        Gate::authorize('delete', $organisation);
        abort_if($organisation->trashed(), 404);

        // Soft delete + cascade to the affiliations: all or nothing.
        DB::transaction(fn () => $organisation->delete());

        return to_route('crm.organisations.index')->with('status', 'Entreprise supprimée. Elle reste récupérable depuis la corbeille.');
    }

    public function restore(Request $request, Organisation $organisation): RedirectResponse
    {
        Gate::authorize('restore', $organisation);
        abort_unless($organisation->trashed(), 404);

        try {
            DB::transaction(fn () => $organisation->restore());
        } catch (UniqueConstraintViolationException $e) {
            // Only the SIRET has a message of its own; any other clash
            // (e.g. the source identifier of an import) stays generic.
            if (! str_contains($e->getMessage(), 'organisations_siret_unique')) {
                throw ValidationException::withMessages(['restore' => self::RESTORE_CONFLICT])->redirectTo(route('crm.organisations.show', $organisation));
            }

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
     * Select options of the form. The current value of the fiche stays
     * listed (and labelled) when it is no longer offered: an inactive
     * reference item, or a deactivated responsible.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function formOptions(?Organisation $current): array
    {
        return [
            'status' => OrganisationStatus::options(),
            'legal_form' => $this->options(ReferenceList::LegalFormOrg, $current?->legal_form_id),
            'sector' => $this->options(ReferenceList::Sector, $current?->sector_id),
            'establishment_type' => $this->options(ReferenceList::EstablishmentType, $current?->establishment_type_id),
            'company_size' => $this->options(ReferenceList::CompanySize, $current?->company_size_id),
            'origin' => $this->options(ReferenceList::ContactOrigin, $current?->origin_id),
            'das' => $this->options(ReferenceList::Das, null, $current === null ? [] : $current->das()->pluck('reference_items.id')->all()),
            'responsible' => FormOptions::responsible($current?->responsible_user_id),
        ];
    }

    /**
     * Every exportable column of a row: the desktop table shows the ones the user chose.
     *
     * @return array<string, ?string>
     */
    private function cells(CrmResource $resource, Organisation $organisation): array
    {
        $export = $resource->export();

        return collect(array_keys($export->columns()))->mapWithKeys(fn (string $c) => [$c => $export->value($organisation, $c)])->all();
    }

    /**
     * @param  list<string>  $currentIds
     * @return list<array{value: string, label: string}>
     */
    private function options(ReferenceList $list, ?string $currentId = null, array $currentIds = []): array
    {
        return FormOptions::reference($list, $currentId, $currentIds);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    private function idOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? $value : null;
    }
}
