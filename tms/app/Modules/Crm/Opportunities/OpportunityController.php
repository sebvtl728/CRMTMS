<?php

namespace App\Modules\Crm\Opportunities;

use App\Http\Controllers\Controller;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\ActivityRows;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteRows;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Support\FilterInput;
use App\Modules\Crm\Support\FormOptions;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Opportunités » screens: list with filters, fiche (stage moves and
 * history), create / edit form, soft delete.
 */
class OpportunityController extends Controller
{
    public function __construct(private readonly OpportunityStage $stages) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = OpportunityListQuery::filters($request->query());
        $query = OpportunityListQuery::build($filters, $user);

        $totals = (clone $query)->reorder()->toBase()
            ->selectRaw('coalesce(sum(opportunities.amount), 0) as amount, coalesce(sum(opportunities.weighted_amount), 0) as weighted')
            ->first();

        return Inertia::render('crm/opportunities/index', [
            'opportunities' => $query->with(OpportunityRows::WITH)->paginate(25)->withQueryString()
                ->through(fn (Opportunity $o) => OpportunityRows::row($o)),
            'totals' => [
                'amount' => number_format((float) ($totals->amount ?? 0), 2, '.', ''),
                'weighted_amount' => number_format((float) ($totals->weighted ?? 0), 2, '.', ''),
            ],
            'filters' => $filters,
            'options' => OpportunityListQuery::options($filters['training']),
            'can' => ['create' => $user->can(Permissions::CREATE), 'export' => $user->can(Permissions::EXPORT)],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Opportunity::class);
        $user = $this->user($request);

        $organisationId = FilterInput::id($request->query('organisation'));
        $personId = FilterInput::id($request->query('person'));
        $organisation = OpportunityAccounts::organisation($organisationId, $user);
        $person = $organisation === null ? OpportunityAccounts::person($personId, $user) : null;
        abort_if(($organisationId !== null || $personId !== null) && $organisation === null && $person === null, 404);

        $ficheDas = $organisation?->das()->pluck('reference_items.id')->all() ?? $person?->das()->pluck('reference_items.id')->all() ?? [];
        $das = FormOptions::reference(ReferenceList::Das);
        $firstStage = collect(self::openStages())->first();

        return Inertia::render('crm/opportunities/form', [
            'opportunity' => null,
            'account' => match (true) {
                $organisation !== null => ['type' => 'organisation', 'id' => $organisation->id, 'name' => $organisation->name, 'individual' => false],
                $person !== null => ['type' => 'person', 'id' => $person->id, 'name' => $person->fullName(), 'individual' => $person->is_individual],
                default => null,
            },
            'defaults' => [
                'das_id' => collect($ficheDas)->first(fn (string $id) => in_array($id, array_column($das, 'value'), true)),
                'stage_id' => $firstStage['value'] ?? null,
                'funding_mode_id' => $person?->funding_mode_id,
                'responsible_user_id' => $user->id,
                'contact_ids' => $person !== null ? [$person->id] : [],
            ],
            'contactOptions' => OpportunityAccounts::contactOptions($organisation?->id, $person?->id),
            'options' => [...$this->formOptions(null), 'training' => OpportunityTrainings::options(null, $person !== null)],
        ]);
    }

    public function store(OpportunityRequest $request): RedirectResponse
    {
        Gate::authorize('create', Opportunity::class);

        $validated = $request->validated();
        $contactIds = array_values($validated['contact_ids'] ?? []);
        $trainings = array_values($validated['training_lines'] ?? []);
        unset($validated['contact_ids'], $validated['training_lines']);
        $validated['responsible_user_id'] = array_key_exists('responsible_user_id', $validated) ? $validated['responsible_user_id'] : $this->user($request)->id;

        $opportunity = DB::transaction(function () use ($validated, $contactIds, $trainings): Opportunity {
            $opportunity = $this->stages->create($validated, $contactIds);
            OpportunityTrainings::sync($opportunity, $trainings);

            return $opportunity;
        });

        return to_route('crm.opportunities.show', $opportunity)->with('status', 'Opportunité créée.');
    }

    public function show(Request $request, Opportunity $opportunity): Response
    {
        $user = $this->user($request);
        Gate::authorize('view', $opportunity);

        $opportunity->load([...OpportunityRows::WITH, 'fundingMode', 'lossReason', 'contacts']);
        $history = $opportunity->stageChanges()->with(['fromStage', 'toStage', 'changer:id,name'])
            ->orderByDesc('changed_at')->orderByDesc('id')->get();
        $open = $opportunity->won_on === null && $opportunity->lost_on === null;

        return Inertia::render('crm/opportunities/show', [
            'opportunity' => [
                ...OpportunityRows::row($opportunity),
                'funding_mode' => $opportunity->fundingMode === null ? null : ['id' => $opportunity->fundingMode->id, 'label' => $opportunity->fundingMode->label],
                'loss_reason' => $opportunity->lossReason?->label,
                'loss_comment' => $opportunity->loss_comment,
                'won_on' => $opportunity->won_on?->toDateString(),
                'lost_on' => $opportunity->lost_on?->toDateString(),
                'cycle_days' => $opportunity->cycleDays(),
                'probability_overridden' => $opportunity->probability_overridden,
                'contacts' => $opportunity->contacts->map(fn ($p) => ['id' => $p->id, 'name' => $p->fullName()])->values()->all(),
                'open' => $open,
            ],
            'history' => $history->map(fn (StageChange $c) => [
                'id' => $c->id,
                'from' => $c->fromStage?->label,
                'to' => $c->toStage?->label,
                'by' => $c->changer?->name,
                'at' => $c->changed_at->toIso8601String(),
            ])->all(),
            // Its actions (the 100 latest), those the user sees.
            'activities' => CrmVisibility::activities(Activity::query(), $user)->with(ActivityRows::WITH)
                ->where('opportunity_id', $opportunity->id)
                ->orderByDesc('happened_at')->orderByDesc('id')->limit(100)->get()
                ->map(fn (Activity $a) => ActivityRows::row($a))->all(),
            'training_summary' => [
                'lines' => OpportunityTrainings::lines($opportunity),
                'estimate' => OpportunityTrainings::proposal($opportunity),
            ],
            'quotes' => QuoteRows::forOpportunity($opportunity, $user),
            'stages' => OpportunityStage::options($opportunity->stage_id),
            'lossReasons' => FormOptions::reference(ReferenceList::LossReason),
            'can' => [
                'update' => $user->can('update', $opportunity),
                'delete' => $user->can('delete', $opportunity),
                'createQuote' => $user->can('create', [Quote::class, $opportunity]),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function edit(Opportunity $opportunity): Response
    {
        Gate::authorize('update', $opportunity);
        $opportunity->load(['organisation:id,name,deleted_at', 'person:id,first_name,last_name,is_individual,deleted_at']);

        return Inertia::render('crm/opportunities/form', [
            'opportunity' => [
                'id' => $opportunity->id,
                'title' => $opportunity->title,
                'das_id' => $opportunity->das_id,
                'stage' => $opportunity->stage()->value('label'),
                'probability' => $opportunity->probability,
                'amount' => $opportunity->amount,
                'expected_close_on' => $opportunity->expected_close_on?->toDateString(),
                'priority_id' => $opportunity->priority_id,
                'funding_mode_id' => $opportunity->funding_mode_id,
                'responsible_user_id' => $opportunity->responsible_user_id,
                'contact_ids' => $opportunity->contacts()->pluck('people.id')->all(),
                'training_lines' => OpportunityTrainings::stored($opportunity),
            ],
            'account' => $opportunity->isIndividual()
                ? ['type' => 'person', 'id' => $opportunity->person_id, 'name' => $opportunity->person?->fullName(), 'individual' => (bool) $opportunity->person?->is_individual]
                : ['type' => 'organisation', 'id' => $opportunity->organisation_id, 'name' => $opportunity->organisation?->name, 'individual' => false],
            'defaults' => null,
            'contactOptions' => OpportunityAccounts::contactOptions($opportunity->organisation_id, $opportunity->person_id),
            'options' => [...$this->formOptions($opportunity), 'training' => OpportunityTrainings::options($opportunity, $opportunity->isIndividual())],
        ]);
    }

    public function update(OpportunityRequest $request, Opportunity $opportunity): RedirectResponse
    {
        Gate::authorize('update', $opportunity);

        $validated = $request->validated();

        DB::transaction(function () use ($opportunity, $validated): void {
            $probability = $validated['probability'] ?? null;
            $contactIds = array_key_exists('contact_ids', $validated) ? array_values($validated['contact_ids'] ?? []) : null;
            $trainings = array_key_exists('training_lines', $validated) ? array_values($validated['training_lines'] ?? []) : null;
            unset($validated['probability'], $validated['contact_ids'], $validated['training_lines']);

            $opportunity->fill($validated);
            if ($probability !== null) {
                $this->stages->setProbability($opportunity, (int) $probability);
            }
            $opportunity->save();

            if ($contactIds !== null) {
                $this->stages->syncContacts($opportunity, $contactIds);
            }
            if ($trainings !== null) {
                OpportunityTrainings::sync($opportunity, $trainings);
            }
        });

        return to_route('crm.opportunities.show', $opportunity)->with('status', 'Opportunité enregistrée.');
    }

    public function destroy(Opportunity $opportunity): RedirectResponse
    {
        Gate::authorize('delete', $opportunity);

        DB::transaction(fn () => $opportunity->delete());

        return to_route('crm.opportunities.index')->with('status', 'Opportunité supprimée.');
    }

    /**
     * Amount the trainings propose (saved lines, or the lines given, typed
     * on the form): computed, never written.
     */
    public function estimate(Request $request, Opportunity $opportunity): JsonResponse
    {
        Gate::authorize('view', $opportunity);

        $lines = null;
        if ($request->has('training_lines')) {
            $request->merge(['training_lines' => OpportunityTrainings::normalise($request->input('training_lines'))]);
            /** @var array{training_lines?: list<array{training_id: string, quantity: string}>} $validated */
            $validated = $request->validate(OpportunityTrainings::rules($opportunity), OpportunityTrainings::messages());
            $lines = $validated['training_lines'] ?? [];
        }

        return response()->json(OpportunityTrainings::proposal($opportunity, $lines));
    }

    public function stage(StageRequest $request, Opportunity $opportunity): RedirectResponse
    {
        Gate::authorize('update', $opportunity);

        $validated = $request->validated();
        $to = ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->findOrFail($validated['stage_id']);
        $wonOn = isset($validated['won_on']) ? CarbonImmutable::parse($validated['won_on'], config('app.timezone')) : null;

        $quote = null;
        if (isset($validated['quote_id'])) {
            if (OpportunityStage::outcome($to) !== 'won') {
                throw ValidationException::withMessages(['quote_id' => OpportunityStage::QUOTE_ONLY_WHEN_WON]);
            }
            $quote = Quote::query()->whereKey($validated['quote_id'])->where('opportunity_id', $opportunity->id)
                ->where('status', QuoteStatus::Accepted)->first()
                ?? throw ValidationException::withMessages(['quote_id' => OpportunityStage::QUOTE_NOT_ACCEPTED]);
        }

        DB::transaction(function () use ($opportunity, $to, $validated, $wonOn, $quote): void {
            $this->stages->move($opportunity, $to, $validated['loss_reason_id'] ?? null, $validated['loss_comment'] ?? null, $wonOn);
            // The amount of the opportunity becomes the accepted quote's total HT.
            if ($quote !== null) {
                $opportunity->forceFill(['amount' => $quote->total_ht])->save();
            }
        });

        return back()->with('status', 'Étape mise à jour : '.$to->label.'.');
    }

    /**
     * Open stages (creation): active, without closing outcome.
     *
     * @return list<array{value: string, label: string, outcome: ?string, probability: int}>
     */
    private static function openStages(): array
    {
        return array_values(array_filter(OpportunityStage::options(null), fn (array $s) => $s['outcome'] === null));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?Opportunity $current): array
    {
        return [
            'das' => FormOptions::reference(ReferenceList::Das, $current?->das_id),
            'stage' => self::openStages(),
            'priority' => FormOptions::reference(ReferenceList::Priority, $current?->priority_id),
            'funding_mode' => FormOptions::reference(ReferenceList::FundingMode, $current?->funding_mode_id),
            'responsible' => FormOptions::responsible($current?->responsible_user_id),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
