<?php

namespace App\Modules\Catalogue;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Exports\ExportCell;
use App\Modules\Core\Exports\XlsxExporter;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Catalogue » screens: list with filters (read by every CRM role), create
 * / edit form, activation (catalogue.manage), deletion (catalogue.delete),
 * Excel export.
 */
class TrainingController extends Controller
{
    public const IN_USE_MESSAGE = 'Cette formation est utilisée (opportunités, devis, sessions) : désactivez-la plutôt.';

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = TrainingListQuery::filters($request->query());
        $export = new TrainingExport;

        return Inertia::render('catalogue/index', [
            'trainings' => TrainingListQuery::build($filters)->with(TrainingExport::relations())->paginate(25)->withQueryString()
                ->through(fn (Training $t) => self::row($t, $export)),
            'filters' => $filters,
            'options' => TrainingListQuery::options(),
            'can' => ['manage' => $user->can(Permissions::MANAGE), 'delete' => $user->can(Permissions::DELETE), 'export' => $user->can(Permissions::EXPORT)],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Training::class);

        return Inertia::render('catalogue/form', [
            'training' => null,
            'options' => self::formOptions(null),
        ]);
    }

    public function store(TrainingRequest $request): RedirectResponse
    {
        Gate::authorize('create', Training::class);

        $validated = $request->validated();
        $funding = array_values($validated['funding_mode_ids'] ?? []);
        unset($validated['funding_mode_ids']);

        $modules = array_key_exists('modules', $validated) ? array_values($validated['modules'] ?? []) : null;
        unset($validated['modules']);

        DB::transaction(function () use ($validated, $funding, $modules): void {
            $training = Training::query()->create($validated);
            $training->syncFundingModes($funding);
            if ($modules !== null) {
                TrainingModules::sync($training, $modules);
            }
        });

        return to_route('catalogue.index')->with('status', 'Formation créée.');
    }

    public function edit(Request $request, Training $training): Response
    {
        Gate::authorize('update', $training);

        return Inertia::render('catalogue/form', [
            'training' => [
                'id' => $training->id,
                'code' => $training->code,
                'title' => $training->title,
                'das_id' => $training->das_id,
                'duration_hours' => $training->duration_hours,
                'duration_days' => $training->duration_days,
                'audience' => $training->audience,
                'prerequisites' => $training->prerequisites,
                'price_company' => $training->price_company,
                'price_individual' => $training->price_individual,
                'is_active' => $training->is_active,
                'description' => $training->description,
                'objectives' => $training->objectives ?? [],
                'methods' => $training->methods,
                'evaluation' => $training->evaluation,
                'sanction' => $training->sanction,
                'accessibility' => $training->accessibility,
                'access_terms' => $training->access_terms,
                'results' => $training->results,
                'modules' => app(Trainings::class)->modules($training->id),
                'modules_hours' => TrainingModules::hours($training),
                'modules_gap' => TrainingModules::gap($training),
                'funding_mode_ids' => $training->fundingModes()->pluck('reference_items.id')->all(),
            ],
            'options' => self::formOptions($training),
            'brochures' => app(Brochures::class)->forTrainingPage($training),
            'can' => ['brochures' => $this->user($request)->can(Permissions::VIEW)],
        ]);
    }

    public function update(TrainingRequest $request, Training $training): RedirectResponse
    {
        Gate::authorize('update', $training);

        $validated = $request->validated();
        $funding = array_key_exists('funding_mode_ids', $validated) ? array_values($validated['funding_mode_ids'] ?? []) : null;
        unset($validated['funding_mode_ids']);

        $modules = array_key_exists('modules', $validated) ? array_values($validated['modules'] ?? []) : null;
        unset($validated['modules']);

        DB::transaction(function () use ($training, $validated, $funding, $modules): void {
            $training->update($validated);
            if ($modules !== null) {
                TrainingModules::sync($training, $modules);
            }
            if ($funding !== null) {
                $training->syncFundingModes($funding);
            }
        });

        return to_route('catalogue.index')->with('status', 'Formation enregistrée.');
    }

    public function toggle(Training $training): RedirectResponse
    {
        Gate::authorize('update', $training);

        $training->update(['is_active' => ! $training->is_active]);

        return back()->with('status', $training->is_active ? 'Formation activée.' : 'Formation désactivée.');
    }

    /**
     * A training used elsewhere (opportunities, quotes: TrainingUsage) is
     * never deleted, only deactivated.
     */
    public function destroy(Training $training): RedirectResponse
    {
        Gate::authorize('delete', $training);

        if (TrainingUsage::isUsed($training->id)) {
            throw ValidationException::withMessages(['training' => self::IN_USE_MESSAGE]);
        }

        DB::transaction(fn () => $training->delete());

        return to_route('catalogue.index')->with('status', 'Formation supprimée.');
    }

    /**
     * Excel export (crm.export): the filters and sort of the list, the
     * columns asked among those offered. Audited with the filters and the
     * row count, never the content.
     */
    public function export(Request $request, XlsxExporter $exporter, AuditRecorder $audit): StreamedResponse
    {
        $resource = new TrainingExport;
        $filters = TrainingListQuery::filters($request->query());
        $columns = self::columns($request, $resource);

        /** @var Builder<Model> $query */
        $query = TrainingListQuery::build($filters);
        $rows = $exporter->count($query);

        $audit->export('catalogue.trainings', [
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'columns' => $columns,
            'rows' => $rows,
        ]);

        return $exporter->export($query, $resource, $this->user($request), $columns, $resource->fileName().'-'.now()->format('Ymd-Hi').'.xlsx', $rows);
    }

    /**
     * A list row: the export cells as French text (« 1 250,50 », « 14,5 »).
     *
     * @return array<string, mixed>
     */
    private static function row(Training $training, TrainingExport $export): array
    {
        $text = fn (string $column) => ExportCell::display($export->value($training, $column));

        return [
            'id' => $training->id,
            'code' => $training->code,
            'title' => $training->title,
            'das' => $training->das?->label,
            'duration_hours' => $text('duration_hours'),
            'duration_days' => $text('duration_days'),
            'price_company' => $text('price_company'),
            'price_individual' => $text('price_individual'),
            'funding_modes' => $training->fundingModes->pluck('label')->values()->all(),
            'is_active' => $training->is_active,
        ];
    }

    /**
     * @return list<string>
     */
    private static function columns(Request $request, TrainingExport $export): array
    {
        $asked = $request->query('columns');

        if ($asked === null || $asked === [] || $asked === '') {
            return $export->defaults();
        }

        $known = array_keys($export->columns());
        if (! is_array($asked) || array_filter($asked, fn ($c) => ! is_string($c) || ! in_array($c, $known, true)) !== []) {
            throw ValidationException::withMessages(['columns' => 'Colonne inconnue.']);
        }

        // The order the user chose, no duplicates.
        return array_values(array_unique($asked));
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private static function formOptions(?Training $current): array
    {
        return [
            'das' => TrainingListQuery::reference(ReferenceList::Das, array_filter([$current?->das_id])),
            'funding_mode' => TrainingListQuery::reference(ReferenceList::FundingMode, $current?->fundingModes()->pluck('reference_items.id')->all() ?? []),
            'modality' => TrainingListQuery::reference(ReferenceList::TrainingModality, $current?->modules()->whereNotNull('modality_id')->pluck('modality_id')->all() ?? []),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
