<?php

namespace App\Modules\Sessions\Dossiers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Exports\CsvExporter;
use App\Modules\Core\Exports\XlsxExporter;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Sessions\AbandonRule;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Enrolments\EnrolmentOptions;
use App\Modules\Sessions\Enrolments\EnrolmentRows;
use App\Modules\Sessions\Fundings\FundingRows;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionListQuery;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Dossiers » screens: list with filters and CSV export, dossier page,
 * create / edit form (contractant, lines), state changes, cancellation (with
 * a reason), trash and restore. Validation and writing: DossierWriter; states:
 * DossierTransitions; rights and scopes: DossierPolicy.
 */
class DossierController extends Controller
{
    public const OUTSIDE_DAS = 'Ce DAS n\'est pas dans vos DAS.';

    /** Route step => transition. */
    public const STEPS = [
        'convention-sent' => 'sendConvention',
        'convention-signed' => 'signConvention',
        'convocations-sent' => 'sendConvocations',
        'running' => 'markRunning',
        'done' => 'markDone',
    ];

    public function __construct(private readonly DossierWriter $writer, private readonly DossierTransitions $transitions) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = DossierListQuery::filters($request->query());
        $page = DossierListQuery::build($filters, $user)->paginate(25)->withQueryString();

        return Inertia::render('sessions/dossiers/index', [
            'dossiers' => [...$page->toArray(), 'data' => DossierRows::rows($page->items(), $user, Dossier::today())],
            'filters' => $filters,
            'options' => DossierListQuery::options(),
            'can' => [
                'create' => $user->can(Permissions::DOSSIERS_UPDATE),
                'trash' => $user->can(Permissions::DOSSIERS_DELETE),
                'export' => $user->can('crm.export'),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * CSV export (« ; », French amounts and dates): the filters and sort of
     * the list, every column. Audited with the filters and the row count,
     * never the content.
     */
    public function export(Request $request, XlsxExporter $counter, CsvExporter $exporter, AuditRecorder $audit): StreamedResponse
    {
        $user = $this->user($request);
        $resource = new DossierExport;
        $filters = DossierListQuery::filters($request->query());
        $columns = $resource->defaults();

        /** @var Builder<Model> $query */
        $query = DossierListQuery::build($filters, $user);
        $rows = $counter->count($query);

        $audit->export('sessions.dossiers', [
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== false),
            'columns' => $columns,
            'rows' => $rows,
        ]);

        return $exporter->export($query, $resource, $user, $columns, $resource->fileName().'-'.now()->format('Ymd-Hi').'.csv');
    }

    /**
     * Pre-flight of the download (the page calls it first): is the export
     * allowed, and why not (too many rows). Writes nothing, journals nothing.
     */
    public function exportCheck(Request $request, XlsxExporter $counter): JsonResponse
    {
        /** @var Builder<Model> $query */
        $query = DossierListQuery::build(DossierListQuery::filters($request->query()), $this->user($request));
        $size = $counter->size($query);
        $allowed = $size['rows'] <= $size['max'];

        return response()->json([
            'rows' => $size['rows'],
            'max' => $size['max'],
            'allowed' => $allowed,
            'message' => $allowed ? null : XlsxExporter::tooManyRows($size['rows'], $size['max']),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Dossier::class);

        return Inertia::render('sessions/dossiers/form', [
            'dossier' => null,
            'defaults' => [
                'company_id' => DossierWriter::defaultCompanyId(),
                'responsible_user_id' => $this->user($request)->id,
            ],
            'options' => self::formOptions(null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Dossier::class);
        $this->assertDas($this->user($request), $request->input('das_id'));

        $dossier = $this->writer->create($request->all());

        return to_route('dossiers.show', $dossier)->with('status', "Dossier {$dossier->number} créé.");
    }

    public function show(Request $request, Dossier $dossier): Response
    {
        $user = $this->user($request);
        Gate::authorize('view', $dossier);
        $dossier->load(['lines', 'responsible:id,name', 'creator:id,name', 'company:id,name']);
        $today = Dossier::today();
        $status = $dossier->effectiveStatus($today);
        $fiches = app(Fiches::class);
        $contact = $dossier->contact_person_id === null ? null : ($fiches->personLabels([$dossier->contact_person_id])[$dossier->contact_person_id] ?? null);
        $trainings = app(Trainings::class)->findMany($dossier->lines->pluck('training_id')->filter()->unique()->values()->all());
        $rule = AbandonRule::forDossier($dossier);
        $open = ! $dossier->isCancelled() && ! $dossier->trashed();
        $update = $open && $user->can('update', $dossier);

        return Inertia::render('sessions/dossiers/show', [
            'dossier' => [
                ...DossierRows::one($dossier, $user, $today),
                'stored_status' => $dossier->status->value,
                'company' => $dossier->company?->name,
                'contact' => $contact === null ? null : [
                    'id' => $dossier->contact_person_id,
                    'label' => $contact['label'],
                    'can_open' => $fiches->openable($user, 'person', [(string) $dossier->contact_person_id]) !== [],
                ],
                'convention_sent_on' => $dossier->convention_sent_on?->toDateString(),
                'convention_signed_on' => $dossier->convention_signed_on?->toDateString(),
                'convocations_sent_on' => $dossier->convocations_sent_on?->toDateString(),
                'cancel_reason' => $dossier->cancelled_reason_id === null ? null : ReferenceItem::query()->find($dossier->cancelled_reason_id)?->label,
                'cancelled_on' => $dossier->cancelled_on?->toDateString(),
                'abandon_rule' => ['label' => AbandonRule::label($rule), 'overridden' => $rule['overridden']],
                'notes' => $dossier->notes,
                'created_by' => $dossier->creator?->name,
                'created_at' => $dossier->created_at?->toIso8601String(),
                'lines' => $dossier->lines->map(fn (DossierLine $l) => [
                    'id' => $l->id,
                    'kind' => $l->kind,
                    'kind_label' => DossierLine::KINDS[$l->kind],
                    'training' => $l->training_id === null || ! isset($trainings[$l->training_id]) ? null
                        : ['id' => $l->training_id, 'title' => $trainings[$l->training_id]->title, 'code' => $trainings[$l->training_id]->code],
                    'label' => $l->label,
                    'quantity' => self::french($l->quantity),
                    'unit' => $l->unit,
                    'unit_price' => Amounts::display(Amounts::cents($l->unit_price)),
                    'discount_percent' => self::french($l->discount_percent),
                    'total_ht' => Amounts::display(Amounts::cents($l->total_ht)),
                    'pricing_mode_label' => $l->pricing_mode === null ? null : DossierLine::PRICING_MODES[$l->pricing_mode],
                ])->values()->all(),
            ],
            'can' => [
                'update' => $update,
                'steps' => [
                    'convention-sent' => $update && $dossier->status === DossierStatus::Created,
                    'convention-signed' => $update && $dossier->status === DossierStatus::ConventionSent,
                    'convocations-sent' => $update && $dossier->status === DossierStatus::ConventionSigned,
                    'running' => $update && in_array($dossier->status, DossierStatus::BEFORE_RUNNING, true),
                    'done' => $update && ! $dossier->status->isDoneOrAfter(),
                ],
                'cancel' => $update && ! $status->isDoneOrAfter(),
                'fund' => $update,
                'delete' => $user->can('delete', $dossier),
                'restore' => $user->can('restore', $dossier),
            ],
            'today' => $today->toDateString(),
            'cancel_reasons' => SessionListQuery::reference(ReferenceList::CancelReason),
            'enrolments' => EnrolmentRows::forDossier($dossier, $user),
            'fundings' => FundingRows::rows($dossier, $user, $update),
            'balance' => FundingRows::balance($dossier),
            'funding_options' => $update ? FundingRows::options($dossier) : null,
            'enrol' => $open && $user->can(Permissions::SESSIONS_UPDATE) ? ['sessions' => EnrolmentOptions::sessionsFor($dossier, $user)] : null,
            'enrolment_references' => $user->can(Permissions::SESSIONS_UPDATE) ? EnrolmentOptions::references() : null,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function edit(Dossier $dossier): Response
    {
        Gate::authorize('update', $dossier);
        $d = $dossier->load('lines');
        $fiches = app(Fiches::class);
        $account = $d->account_organisation_id !== null
            ? ($fiches->organisationLabels([$d->account_organisation_id])[$d->account_organisation_id] ?? null)
            : ($fiches->personLabels([(string) $d->account_person_id])[(string) $d->account_person_id] ?? null);
        $contact = $d->contact_person_id === null ? null : ($fiches->personLabels([$d->contact_person_id])[$d->contact_person_id] ?? null);

        return Inertia::render('sessions/dossiers/form', [
            'dossier' => [
                'id' => $d->id,
                'number' => $d->number,
                'cancelled' => $d->isCancelled(),
                'company_id' => $d->company_id,
                'account' => ['type' => $d->accountType(), 'id' => $d->accountId(), 'label' => $account['label'] ?? 'Fiche introuvable'],
                'contact' => $contact === null ? null : ['id' => $d->contact_person_id, 'label' => $contact['label']],
                'responsible_user_id' => $d->responsible_user_id,
                'das_id' => $d->das_id,
                'abandon_mode' => $d->abandon_mode,
                'abandon_dedit_percent' => $d->abandon_dedit_percent,
                'notes' => $d->notes,
                'lines' => $d->lines->map(fn (DossierLine $l) => [
                    'id' => $l->id,
                    'kind' => $l->kind,
                    'training_id' => $l->training_id,
                    'label' => $l->label,
                    'quantity' => self::french($l->quantity, trim: true),
                    'unit' => $l->unit,
                    'unit_price' => self::french($l->unit_price),
                    'discount_percent' => self::french($l->discount_percent, trim: true),
                    'pricing_mode' => $l->pricing_mode,
                ])->values()->all(),
            ],
            'defaults' => null,
            'options' => self::formOptions($d),
        ]);
    }

    public function update(Request $request, Dossier $dossier): RedirectResponse
    {
        Gate::authorize('update', $dossier);
        if ($request->input('das_id') !== $dossier->das_id) {
            $this->assertDas($this->user($request), $request->input('das_id'));
        }

        $this->writer->update($dossier, $request->all());

        return to_route('dossiers.show', $dossier)->with('status', 'Dossier enregistré.');
    }

    /**
     * A change of state: the three dated steps (date « on », today by
     * default), « En cours », « Réalisé ».
     */
    public function step(Request $request, Dossier $dossier, string $step): RedirectResponse
    {
        Gate::authorize('update', $dossier);
        $method = self::STEPS[$step];

        if (in_array($method, ['markRunning', 'markDone'], true)) {
            $this->transitions->{$method}($dossier);
        } else {
            $this->transitions->{$method}($dossier, self::day($request->input('on')));
        }

        return to_route('dossiers.show', $dossier)->with('status', "Dossier {$dossier->number} : {$dossier->status->label()}.");
    }

    public function cancel(Request $request, Dossier $dossier): RedirectResponse
    {
        Gate::authorize('update', $dossier);
        $reason = $request->input('cancelled_reason_id');

        $this->transitions->cancel($dossier, is_string($reason) ? $reason : null);

        return to_route('dossiers.show', $dossier)->with('status', 'Dossier annulé.');
    }

    public function destroy(Dossier $dossier): RedirectResponse
    {
        Gate::authorize('delete', $dossier);

        $this->writer->trash($dossier);

        return to_route('dossiers.index')->with('status', "Dossier {$dossier->number} mis à la corbeille.");
    }

    public function restore(Dossier $dossier): RedirectResponse
    {
        Gate::authorize('restore', $dossier);

        DB::transaction(fn () => $dossier->restore());

        return to_route('dossiers.show', $dossier)->with('status', 'Dossier restauré.');
    }

    /**
     * Entreprises of the organisme for the contractant (identity only).
     */
    public function organisations(Request $request, Fiches $fiches): JsonResponse
    {
        Gate::authorize('create', Dossier::class);
        $q = $request->query('q');

        return response()->json(['entreprises' => is_string($q) && mb_strlen(trim($q)) >= 2 ? $fiches->searchOrganisations(mb_substr($q, 0, 100)) : []]);
    }

    /**
     * People of the organisme for the particulier or the contact (minimal
     * identity, the employees of « organisation » first).
     */
    public function people(Request $request, Trainees $trainees): JsonResponse
    {
        Gate::authorize('create', Dossier::class);
        $q = $request->query('q');
        $organisation = $request->query('organisation');

        return response()->json(['personnes' => is_string($q) && mb_strlen(trim($q)) >= 2
            ? $trainees->search(mb_substr($q, 0, 100), is_string($organisation) ? $organisation : null)
            : []]);
    }

    /**
     * « Ses DAS » on sessions.dossiers.update: the dossier's DAS must be one
     * he follows (Own and All: any).
     */
    private function assertDas(User $user, mixed $dasId): void
    {
        if (is_string($dasId) && $dasId !== '' && ! SessionsVisibility::allowsDas($user, Permissions::DOSSIERS_UPDATE, $dasId)) {
            throw ValidationException::withMessages(['das_id' => self::OUTSIDE_DAS]);
        }
    }

    /**
     * A day typed « 2026-12-01 » (default today in Paris).
     *
     * @throws ValidationException
     */
    private static function day(mixed $value): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return Dossier::today();
        }
        $day = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Paris') : false;
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['on' => 'Date invalide.']);
        }

        return $day;
    }

    /** « 1250.50 » → « 1250,50 »; trimmed: « 2.50 » → « 2,5 », « 3.00 » → « 3 ». */
    private static function french(string $decimal, bool $trim = false): string
    {
        if ($trim && str_contains($decimal, '.')) {
            $decimal = rtrim(rtrim($decimal, '0'), '.');
        }

        return str_replace('.', ',', $decimal);
    }

    /**
     * @return array<string, mixed>
     */
    private static function formOptions(?Dossier $current): array
    {
        $trainings = array_map(fn ($t) => [
            'value' => $t->id,
            'label' => $t->code === null ? $t->title : "{$t->title} ({$t->code})",
            'title' => $t->title,
            'das_id' => $t->das_id,
            'price_company' => $t->price_company_cents === null ? null : self::french(Amounts::decimal($t->price_company_cents)),
            'price_individual' => $t->price_individual_cents === null ? null : self::french(Amounts::decimal($t->price_individual_cents)),
        ], app(Trainings::class)->options());
        $known = array_column($trainings, 'value');
        foreach ($current?->lines->pluck('training_id')->filter()->unique() ?? [] as $id) {
            $kept = in_array($id, $known, true) ? null : app(Trainings::class)->find($id);
            if ($kept !== null) {
                $trainings[] = ['value' => $kept->id, 'label' => $kept->title.' (désactivée)', 'title' => $kept->title, 'das_id' => $kept->das_id, 'price_company' => null, 'price_individual' => null];
            }
        }

        return [
            'training' => $trainings,
            'company' => Company::query()->where(fn ($q) => $q->whereNull('deleted_at')->when($current?->company_id, fn ($q2, $id) => $q2->orWhere('id', $id)))
                ->withTrashed()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'deleted_at'])
                ->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->trashed() ? $c->name.' (supprimée)' : $c->name])->values()->all(),
            'das' => SessionListQuery::reference(ReferenceList::Das, array_filter([$current?->das_id])),
            'responsible' => SessionListQuery::responsible($current?->responsible_user_id),
            'kind' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(DossierLine::KINDS), DossierLine::KINDS),
            'unit' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(DossierLine::UNITS), DossierLine::UNITS),
            'pricing_mode' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(DossierLine::PRICING_MODES), DossierLine::PRICING_MODES),
            'abandon_rule' => AbandonRule::label(AbandonRule::forTenant()),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
