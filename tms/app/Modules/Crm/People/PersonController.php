<?php

namespace App\Modules\Crm\People;

use App\Http\Controllers\Controller;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Duplicates\DuplicateGuard;
use App\Modules\Crm\Gdpr\Consent;
use App\Modules\Crm\Gdpr\ConsentChannel;
use App\Modules\Crm\Gdpr\GdprPending;
use App\Modules\Crm\Gdpr\LegalBasis;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationStatus;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Search\CrmResource;
use App\Modules\Crm\Search\ListExtras;
use App\Modules\Crm\Support\DasLinks;
use App\Modules\Crm\Support\FormOptions;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * « Personnes » screens (particuliers and interlocuteurs): list with
 * filters, fiche, create / edit form, soft delete and restore.
 */
class PersonController extends Controller
{
    public function __construct(private readonly DuplicateGuard $duplicates, private readonly DasLinks $das) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $canDelete = $user->can(Permissions::DELETE);

        $filters = PersonListQuery::filters($request->query(), $user);
        $query = PersonListQuery::build($filters, $user)->with(CrmResource::People->export()->with());

        return Inertia::render('crm/people/index', [
            'people' => $query->paginate(25)->withQueryString()->through(fn (Person $p) => [
                'id' => $p->id,
                'civility' => $p->civility,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'name' => $p->fullName(),
                'is_individual' => $p->is_individual,
                'organisations' => $p->activeAffiliations->filter(fn ($a) => $a->organisation !== null)
                    ->map(fn ($a) => ['id' => $a->organisation_id, 'name' => $a->organisation?->name, 'job_title' => $a->job_title])->values()->all(),
                'email' => $p->email ?? $p->activeAffiliations->pluck('work_email')->filter()->first(),
                'phone' => $p->mobile ?? $p->phone ?? $p->activeAffiliations->pluck('work_phone')->filter()->first(),
                'city' => $p->city,
                'status' => ['value' => $p->status->value, 'label' => $p->status->label()],
                'responsible' => $p->responsible?->name,
                'last_contact_at' => $p->last_contact_at?->toIso8601String(),
                'created_at' => $p->created_at?->toIso8601String(),
                'deleted' => $p->trashed(),
                'prospecting_opt_out' => $p->prospecting_opt_out_at !== null,
                'anonymised' => $p->anonymised_at !== null,
                'cells' => $this->cells($p),
            ]),
            'filters' => $filters,
            'options' => [
                'type' => [['value' => 'individual', 'label' => 'Particuliers'], ['value' => 'contact', 'label' => 'Interlocuteurs']],
                'status' => OrganisationStatus::options(),
                'situation' => FormOptions::reference(ReferenceList::ProfessionalSituation),
                'funding' => FormOptions::reference(ReferenceList::FundingMode),
                'origin' => FormOptions::reference(ReferenceList::ContactOrigin),
                'responsible' => FormOptions::responsible(),
            ],
            'can' => ['create' => $user->can(Permissions::CREATE), 'delete' => $canDelete, 'export' => $user->can(Permissions::EXPORT), 'import' => $user->can(Permissions::IMPORT)],
            ...ListExtras::for(CrmResource::People, $user, $request->query('saved')),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Every exportable column of a row: the desktop table shows the ones the user chose.
     *
     * @return array<string, ?string>
     */
    private function cells(Person $person): array
    {
        $export = CrmResource::People->export();

        return collect(array_keys($export->columns()))->mapWithKeys(fn (string $c) => [$c => $export->value($person, $c)])->all();
    }

    /**
     * People to attach to an entreprise (autocomplete of the interlocuteurs
     * tab): visible people matching the text, minus those already attached.
     */
    public function search(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $term = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $organisationId = $this->idOrNull($request->query('organisation'));

        if (mb_strlen($term) < 2) {
            return response()->json(['people' => []]);
        }

        $people = CrmVisibility::people(Person::query(), $user)
            ->with('activeAffiliations.organisation')
            ->search($term)
            ->when($organisationId, fn (Builder $q, string $id) => $q->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('affiliations')
                ->whereColumn('affiliations.person_id', 'people.id')->where('affiliations.organisation_id', $id)
                ->whereNull('affiliations.ended_on')->whereNull('affiliations.deleted_at')))
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(10)->get();

        return response()->json(['people' => $people->map(fn (Person $p) => [
            'id' => $p->id,
            'first_name' => $p->first_name,
            'last_name' => $p->last_name,
            'name' => $p->fullName(),
            'email' => $p->email,
            'prospecting_opt_out' => $p->prospecting_opt_out_at !== null,
            'organisations' => $p->activeAffiliations->map(fn ($a) => $a->organisation?->name)->filter()->values()->all(),
        ])->all()]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Person::class);

        return Inertia::render('crm/people/form', [
            'person' => null,
            'options' => $this->formOptions(null),
            'duplicates' => DuplicateGuard::flashed($request),
            'canMerge' => $this->user($request)->can(Permissions::MERGE),
        ]);
    }

    public function store(PersonRequest $request): HttpResponse
    {
        Gate::authorize('create', Person::class);

        $duplicate = $this->duplicates->person($request, $request->validated(), null, route('crm.people.create'));
        if ($duplicate !== null) {
            return $duplicate;
        }

        $person = $this->persist(new Person, $request);

        // « Fusionner » from the warning: created, then straight to the merge screen.
        $mergeInto = $request->input('merge_into');
        if (is_string($mergeInto) && $this->user($request)->can(Permissions::MERGE)
            && CrmVisibility::people(Person::query(), $this->user($request))->whereKey($this->idOrNull($mergeInto))->exists()) {
            return to_route('crm.merge.show', ['type' => 'person', 'keep' => $mergeInto, 'drop' => $person->id]);
        }

        return to_route('crm.people.show', $person)->with('status', 'Personne créée.');
    }

    public function show(Request $request, Person $person): Response
    {
        $user = $this->user($request);
        Gate::authorize('view', $person);

        $person->load(['professionalSituation', 'fundingMode', 'origin', 'responsible', 'das']);
        $label = fn (?ReferenceItem $i) => $i === null ? null : ['id' => $i->id, 'label' => $i->label];

        $affiliations = $person->affiliations()
            ->with(['organisation', 'decisionRole'])
            ->whereIn('organisation_id', CrmVisibility::organisations(Organisation::query(), $user)->select('organisations.id'))
            ->orderByRaw('ended_on is not null')->orderByDesc('started_on')->orderByDesc('created_at')
            ->get();

        return Inertia::render('crm/people/show', [
            'person' => [
                ...$this->fields($person),
                'name' => $person->fullName(),
                'status' => ['value' => $person->status->value, 'label' => $person->status->label()],
                'professional_situation' => $label($person->professionalSituation),
                'funding_mode' => $label($person->fundingMode),
                'origin' => $label($person->origin),
                'responsible' => $person->responsible === null ? null : ['id' => $person->responsible->id, 'name' => $person->responsible->name],
                'das' => $person->das->map($label)->all(),
                'affiliations' => $affiliations->filter(fn ($a) => $a->organisation !== null)->map(fn ($a) => [
                    'id' => $a->id,
                    'organisation' => ['id' => $a->organisation_id, 'name' => $a->organisation?->name],
                    'job_title' => $a->job_title,
                    'decision_role' => $label($a->decisionRole),
                    'work_email' => $a->work_email,
                    'work_phone' => $a->work_phone,
                    'is_referent' => $a->is_referent,
                    'started_on' => $a->started_on?->toDateString(),
                    'ended_on' => $a->ended_on?->toDateString(),
                ])->values()->all(),
                'last_contact_at' => $person->last_contact_at?->toIso8601String(),
                'created_at' => $person->created_at?->toIso8601String(),
                'updated_at' => $person->updated_at?->toIso8601String(),
                'deleted' => $person->trashed(),
                'legal_basis' => ['value' => $person->legal_basis->value, 'label' => $person->legal_basis->label()],
                'prospecting_opt_out_at' => $person->prospecting_opt_out_at?->toIso8601String(),
                'anonymised' => $person->anonymised_at !== null,
                'anonymised_at' => $person->anonymised_at?->toIso8601String(),
            ],
            'gdpr' => $this->gdpr($person, $user),
            'similar' => $user->can(Permissions::MERGE) ? $this->duplicates->similarTo($person, $user) : [],
            'can' => [
                'merge' => ! $person->trashed() && $person->anonymised_at === null && $user->can(Permissions::MERGE),
                'update' => ! $person->trashed() && $user->can('update', $person),
                'delete' => ! $person->trashed() && $user->can('delete', $person),
                'restore' => $person->trashed() && $user->can('restore', $person),
                'consent' => ! $person->trashed() && $user->can('update', $person),
                'gdpr' => $user->can('gdpr', $person),
                'anonymise' => $person->anonymised_at === null && $user->can('gdpr', $person),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function edit(Request $request, Person $person): Response
    {
        Gate::authorize('update', $person);
        abort_if($person->trashed(), 404);

        return Inertia::render('crm/people/form', [
            'person' => [
                ...$this->fields($person),
                'status' => $person->status->value,
                'das_ids' => $person->das()->pluck('reference_items.id')->all(),
            ],
            'options' => $this->formOptions($person),
            'duplicates' => DuplicateGuard::flashed($request),
            'canMerge' => $this->user($request)->can(Permissions::MERGE),
        ]);
    }

    public function update(PersonRequest $request, Person $person): HttpResponse
    {
        Gate::authorize('update', $person);
        abort_if($person->trashed(), 404);

        $duplicate = $this->duplicates->person($request, $request->validated(), $person, route('crm.people.edit', $person));
        if ($duplicate !== null) {
            return $duplicate;
        }

        $this->persist($person, $request);

        return to_route('crm.people.show', $person)->with('status', 'Personne enregistrée.');
    }

    public function destroy(Person $person): RedirectResponse
    {
        Gate::authorize('delete', $person);
        abort_if($person->trashed(), 404);

        DB::transaction(fn () => $person->delete());

        return to_route('crm.people.index')->with('status', 'Personne supprimée. Elle reste récupérable depuis la corbeille.');
    }

    public function restore(Person $person): RedirectResponse
    {
        Gate::authorize('restore', $person);
        abort_unless($person->trashed(), 404);

        DB::transaction(fn () => $person->restore());

        return to_route('crm.people.show', $person)->with('status', 'Personne restaurée.');
    }

    private function persist(Person $person, PersonRequest $request): Person
    {
        $validated = $request->validated();
        $dasIds = array_key_exists('das_ids', $validated) ? array_values($validated['das_ids']) : null;
        unset($validated['das_ids']);
        if (array_key_exists('legal_basis', $validated) && $validated['legal_basis'] === null) {
            unset($validated['legal_basis']); // creation: default from the type of person
        }
        $validated['country'] ??= 'FR';
        $validated['status'] ??= $person->exists ? $person->status->value : OrganisationStatus::Prospect->value;

        DB::transaction(function () use ($person, $validated, $dasIds): void {
            $person->fill($validated)->save();

            if ($dasIds !== null) {
                $this->das->sync($person, $dasIds);
            }
        });

        return $person;
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(Person $p): array
    {
        return [
            'id' => $p->id,
            'civility' => $p->civility,
            'first_name' => $p->first_name,
            'last_name' => $p->last_name,
            'email' => $p->email,
            'email_secondary' => $p->email_secondary,
            'phone' => $p->phone,
            'mobile' => $p->mobile,
            'address_line1' => $p->address_line1,
            'address_line2' => $p->address_line2,
            'postal_code' => $p->postal_code,
            'city' => $p->city,
            'country' => $p->country,
            'birth_date' => $p->birth_date?->toDateString(),
            'is_individual' => $p->is_individual,
            'project' => $p->project,
            'professional_situation_id' => $p->professional_situation_id,
            'funding_mode_id' => $p->funding_mode_id,
            'origin_id' => $p->origin_id,
            'responsible_user_id' => $p->responsible_user_id,
            'legal_basis' => $p->legal_basis->value,
        ];
    }

    /**
     * RGPD section of the fiche: consents (newest first), listing and keep.
     *
     * @return array<string, mixed>
     */
    private function gdpr(Person $person, User $user): array
    {
        $pending = GdprPending::query()->find($person->id);

        return [
            'consents' => Consent::query()->with('recorder:id,name')->where('person_id', $person->id)
                ->orderByDesc('recorded_at')->orderByDesc('id')->get()
                ->map(fn (Consent $c) => [
                    'id' => $c->id,
                    'channel' => ['value' => $c->channel->value, 'label' => $c->channel->label()],
                    'granted' => $c->granted,
                    'source' => $c->source,
                    'recorded_at' => $c->recorded_at->toIso8601String(),
                    'recorded_by' => $c->recorder?->name,
                ])->all(),
            'channels' => ConsentChannel::options(),
            'pending' => $pending === null ? null : ['listed_at' => $pending->listed_at->toDateString(), 'planned_at' => $pending->plannedAt()->toDateString()],
            'kept_until' => $person->gdpr_kept_until !== null && $person->gdpr_kept_until->isFuture() ? $person->gdpr_kept_until->toDateString() : null,
            'kept_reason' => $user->can('gdpr', $person) && $person->gdpr_kept_until?->isFuture() ? $person->gdpr_kept_reason : null,
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function formOptions(?Person $current): array
    {
        return [
            'status' => OrganisationStatus::options(),
            'professional_situation' => FormOptions::reference(ReferenceList::ProfessionalSituation, $current?->professional_situation_id),
            'funding_mode' => FormOptions::reference(ReferenceList::FundingMode, $current?->funding_mode_id),
            'origin' => FormOptions::reference(ReferenceList::ContactOrigin, $current?->origin_id),
            'das' => FormOptions::reference(ReferenceList::Das, null, $current === null ? [] : $current->das()->pluck('reference_items.id')->all()),
            'responsible' => FormOptions::responsible($current?->responsible_user_id),
            'legal_basis' => LegalBasis::options(),
        ];
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
