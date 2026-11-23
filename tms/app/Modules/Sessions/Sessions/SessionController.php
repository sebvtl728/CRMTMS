<?php

namespace App\Modules\Sessions\Sessions;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\EnrolmentOptions;
use App\Modules\Sessions\Enrolments\EnrolmentRows;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Notes\SessionNotes;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\PlacementPeriods;
use App\Modules\Sessions\Planning\PublicHolidays;
use App\Modules\Sessions\Planning\SeanceRows;
use App\Modules\Sessions\Planning\SessionDays;
use App\Modules\Sessions\Planning\WorkingDays;
use App\Modules\Sessions\Public\SessionBlocks;
use App\Modules\Sessions\Resources\ClosurePeriods;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Summary\Card;
use App\Modules\Sessions\Summary\SessionChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Sessions » screens: list with filters, monthly calendar, session page,
 * create / edit form, cancellation (with a reason), trash and restore.
 * Validation and writing: SessionWriter; rights and scopes: SessionPolicy.
 */
class SessionController extends Controller
{
    public const OUTSIDE_DAS = 'Cette formation n\'est pas dans vos DAS.';

    /** Steps of the session page shown to whoever views the session (« suivi » added with the attendance rights). */
    public const STEPS = ['synthese', 'configuration', 'planning', 'stagiaires'];

    /** French month names (no locale dependency). */
    public const MONTHS = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public function __construct(private readonly SessionWriter $writer) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = SessionListQuery::filters($request->query());
        $today = TrainingSession::today();

        // « Liste » (default) or « Colonnes » (the kanban, spec § 4.3).
        $view = $request->query('vue') === 'colonnes' ? 'colonnes' : 'liste';
        $withCancelled = filter_var($request->query('annulees', false), FILTER_VALIDATE_BOOL);

        $page = $view === 'liste' ? SessionListQuery::build($filters, $user, $today)->paginate(25)->withQueryString() : null;

        return Inertia::render('sessions/sessions/index', [
            'view' => $view,
            'sessions' => $page === null ? null : [...$page->toArray(), 'data' => SessionRows::rows($page->items(), $today)],
            'board' => $view === 'colonnes' ? SessionBoard::build($filters, $user, $today, $withCancelled) : null,
            'with_cancelled' => $withCancelled,
            'filters' => $filters,
            'options' => SessionListQuery::options(),
            'can' => ['create' => $user->can(Permissions::SESSIONS_UPDATE), 'trash' => $user->can(Permissions::SESSIONS_DELETE)],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function calendar(Request $request): Response
    {
        $user = $this->user($request);
        $month = $request->query('month');
        $first = is_string($month) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) === 1
            ? CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'Europe/Paris')
            : TrainingSession::today()->startOfMonth();
        assert($first instanceof CarbonImmutable);
        $filters = [
            'kind' => SessionListQuery::filters(['kind' => $request->query('kind')])['kind'],
            'modality' => SessionListQuery::filters(['modality' => $request->query('modality')])['modality'],
            'das' => SessionListQuery::filters(['das' => $request->query('das')])['das'],
        ];

        // The weeks of the grid (Monday to Sunday): a session shows on its séance days or on working days only.
        $gridFrom = $first->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $gridTo = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();
        $sessions = SessionListQuery::month($first, $user, $filters)->get();
        $closures = ClosurePeriods::between(WorkingDays::day($gridFrom), WorkingDays::day($gridTo));
        $days = SessionDays::for($sessions, $gridFrom, $gridTo, $closures);

        return Inertia::render('sessions/sessions/calendar', [
            'month' => $first->format('Y-m'),
            'label' => self::MONTHS[$first->month].' '.$first->year,
            'prev' => $first->subMonth()->format('Y-m'),
            'next' => $first->addMonth()->format('Y-m'),
            'today' => TrainingSession::today()->toDateString(),
            'sessions' => array_map(fn (array $row) => [...$row, 'days' => $days[$row['id']] ?? []], SessionRows::rows($sessions, TrainingSession::today())),
            'holidays' => PublicHolidays::between($gridFrom, $gridTo),
            'closures' => SeanceRows::closures($closures),
            'filters' => $filters,
            'options' => ['kind' => SessionKind::options(), 'modality' => SessionListQuery::reference(ReferenceList::TrainingModality), 'das' => SessionListQuery::reference(ReferenceList::Das)],
            'can' => ['create' => $user->can(Permissions::SESSIONS_UPDATE)],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', TrainingSession::class);
        $organisation = is_string($request->query('organisation')) ? app(Fiches::class)->organisation($request->query('organisation')) : null;

        return Inertia::render('sessions/sessions/form', [
            'session' => null,
            'defaults' => [
                'kind' => $organisation === null ? 'inter' : 'intra',
                'organisation' => $organisation,
                'responsible_user_id' => $this->user($request)->id,
                // From a training page: preselected only when offered and in the user's DAS (else ignored).
                'training_id' => $this->preselectedTraining($this->user($request), $request->query('training')),
            ],
            'options' => self::formOptions(null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('create', TrainingSession::class);
        $this->assertDas($user, $request->input('training_id'));

        $session = $this->writer->create($request->all());

        return to_route('sessions.show', $session)->with('status', "Session {$session->number} créée.");
    }

    public function show(Request $request, TrainingSession $trainingSession): Response
    {
        $user = $this->user($request);
        Gate::authorize('view', $trainingSession);
        $session = $trainingSession->load(['responsible:id,name', 'creator:id,name']);
        $cancelReason = $session->cancel_reason_id === null ? null : ReferenceItem::query()->find($session->cancel_reason_id)?->label;

        $row = SessionRows::one($session, TrainingSession::today());
        $enrol = ! $session->isCancelled() && $user->can('update', $session);
        if (is_array($row['organisation'])) {
            // A link to the fiche only when the user can open it (plain text otherwise).
            $row['organisation']['can_open'] = app(Fiches::class)->openable($user, 'organisation', [(string) $session->organisation_id]) !== [];
        }

        return Inertia::render('sessions/sessions/show', [
            'session' => [
                ...$row,
                'stored_status' => $session->status->value,
                'notes' => $session->notes,
                'cancel_reason' => $cancelReason,
                'cancelled_on' => $session->cancelled_on?->toDateString(),
                'created_by' => $session->creator?->name,
                'created_at' => $session->created_at?->toIso8601String(),
            ],
            'can' => [
                'update' => ! $session->isCancelled() && $user->can('update', $session),
                'cancel' => ! $session->isCancelled() && $user->can('update', $session),
                'delete' => $user->can('delete', $session),
                'restore' => $user->can('restore', $session),
            ],
            'cancel_reasons' => SessionListQuery::reference(ReferenceList::CancelReason),
            'enrolments' => EnrolmentRows::forSession($session, $user),
            'places' => [
                'capacity' => $session->capacity,
                'taken' => $taken = app(Enrolments::class)->placesTaken($session),
                'free' => $session->capacity === null ? null : max(0, $session->capacity - $taken),
            ],
            'enrol' => $enrol ? ['dossiers' => EnrolmentOptions::dossiersFor($session, $user)] : null,
            // Choices of the enrolment dialogs (abandon, details) for who may change them.
            'enrolment_references' => $user->can('update', $session) ? EnrolmentOptions::references() : null,
            'planning' => self::planning($session, ! $session->isCancelled() && $user->can('update', $session), $user),
            // « Suivi » step (loaded as JSON): attendance managed or closed on the session.
            'attendance_tab' => $attendance = SessionsVisibility::allows($user, Permissions::ATTENDANCE_MANAGE, $session)
                || SessionsVisibility::allows($user, Permissions::ATTENDANCE_CLOSE, $session),
            // Steps of the page (spec § 4.1), switched in the browser: every datum stays loaded.
            'steps' => [...self::STEPS, ...($attendance ? ['suivi'] : [])],
            // Cards of the « Synthèse » (none once cancelled).
            'checklist' => array_map(fn (Card $card) => $card->toArray(), SessionChecklist::for($session, $user)),
            'notes_panel' => app(SessionNotes::class)->panel($session, $user),
            // Blocks of the other modules (SessionBlocks: documents…), on the « Stagiaires » step.
            'blocks' => SessionBlocks::for($session->id, $user),
            'status' => $request->session()->get('status'),
            'warning' => $request->session()->get('warning'),
        ]);
    }

    public function edit(TrainingSession $trainingSession): Response
    {
        Gate::authorize('update', $trainingSession);
        $s = $trainingSession;
        $organisation = $s->organisation_id === null ? null : app(Fiches::class)->organisationLabels([$s->organisation_id])[$s->organisation_id] ?? null;

        return Inertia::render('sessions/sessions/form', [
            'session' => [
                'id' => $s->id,
                'number' => $s->number,
                'title' => $s->title,
                'cancelled' => $s->isCancelled(),
                'training_id' => $s->training_id,
                'kind' => $s->kind,
                'modality_id' => $s->modality_id,
                'organisation' => $organisation === null ? null : ['id' => $s->organisation_id, 'label' => $organisation['label']],
                'starts_on' => $s->starts_on->toDateString(),
                'ends_on' => $s->ends_on->toDateString(),
                'planned_hours' => SessionRows::hours($s->planned_hours),
                'location_kind' => $s->location_kind,
                'location_text' => $s->location_text,
                'location_company_id' => $s->location_company_id,
                'capacity' => $s->capacity,
                'responsible_user_id' => $s->responsible_user_id,
                'status' => in_array($s->status, SessionStatus::SETTABLE, true) ? $s->status->value : SessionStatus::Planned->value,
                'notes' => $s->notes,
            ],
            'defaults' => null,
            'options' => self::formOptions($s),
        ]);
    }

    public function update(Request $request, TrainingSession $trainingSession): RedirectResponse
    {
        Gate::authorize('update', $trainingSession);
        if ($request->input('training_id') !== $trainingSession->training_id) {
            $this->assertDas($this->user($request), $request->input('training_id'));
        }

        $this->writer->update($trainingSession, $request->all());

        return to_route('sessions.show', $trainingSession)->with('status', 'Session enregistrée.');
    }

    public function cancel(Request $request, TrainingSession $trainingSession): RedirectResponse
    {
        Gate::authorize('update', $trainingSession);
        $reason = $request->input('cancel_reason_id');

        $this->writer->cancel($trainingSession, is_string($reason) ? $reason : null);

        return to_route('sessions.show', $trainingSession)->with('status', 'Session annulée.');
    }

    public function destroy(TrainingSession $trainingSession): RedirectResponse
    {
        Gate::authorize('delete', $trainingSession);

        $this->writer->trash($trainingSession);

        return to_route('sessions.index')->with('status', "Session {$trainingSession->number} mise à la corbeille.");
    }

    public function restore(TrainingSession $trainingSession): RedirectResponse
    {
        Gate::authorize('restore', $trainingSession);

        $this->writer->restore($trainingSession);

        return to_route('sessions.show', $trainingSession)->with('status', 'Session restaurée.');
    }

    /**
     * The « Planning » tab: modules (planned and planned-in-séances
     * minutes), their total, the warnings, the séances the viewer's
     * sessions.seances.view scope reaches (with their alerts), the
     * placements, what the calendar shades, and the choices of the forms
     * for who may change modules (sessions.sessions.update) or plan séances
     * (sessions.seances.manage and its scope).
     *
     * @return array<string, mixed>
     */
    private static function planning(TrainingSession $session, bool $manage, User $user): array
    {
        $modules = SessionModules::rows($session);
        $seances = ! $session->isCancelled() && SessionsVisibility::allows($user, Permissions::SEANCES_MANAGE, $session);

        return [
            'modules' => $modules,
            'total' => SessionModules::totalLabel($session),
            'warnings' => SessionModules::warnings($session),
            'can_manage' => $manage,
            'can_seances' => $seances,
            'seances' => SeanceRows::forSession($session, $user),
            'seance_modules' => array_values(array_map(fn (array $m) => ['value' => $m['id'], 'label' => $m['title']],
                array_filter($modules, fn (array $m) => $m['dating_mode'] === DatingMode::Seances->value))),
            'placements' => PlacementPeriods::rows($session),
            'calendar' => SeanceRows::calendar($session),
            'options' => $manage || $seances ? [
                'modality' => SessionListQuery::reference(ReferenceList::TrainingModality, array_column($modules, 'modality_id')),
                'dating_mode' => DatingMode::options(),
                ...($seances ? SeanceRows::options($session) : []),
            ] : null,
        ];
    }

    /**
     * Entreprises of the organisme for an intra session (identity only).
     */
    public function organisations(Request $request, Fiches $fiches): JsonResponse
    {
        Gate::authorize('create', TrainingSession::class);
        $q = $request->query('q');

        return response()->json(['entreprises' => is_string($q) && mb_strlen(trim($q)) >= 2 ? $fiches->searchOrganisations(mb_substr($q, 0, 100)) : []])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * The training asked by « Créer une session » (`?training=`), kept when
     * it is active and within the DAS $user may create sessions in; else none.
     */
    private function preselectedTraining(User $user, mixed $trainingId): ?string
    {
        $training = is_string($trainingId) ? app(Trainings::class)->find($trainingId) : null;
        if ($training === null || ! $training->is_active) {
            return null;
        }
        try {
            $this->assertDas($user, $training->id);
        } catch (ValidationException) {
            return null;
        }

        return $training->id;
    }

    /**
     * « Ses DAS » on sessions.sessions.update: the training must be in one
     * of the DAS he follows (Own and All: any).
     */
    private function assertDas(User $user, mixed $trainingId): void
    {
        $training = is_string($trainingId) ? app(Trainings::class)->find($trainingId) : null;
        if ($training !== null && ! SessionsVisibility::allowsDas($user, Permissions::SESSIONS_UPDATE, $training->das_id)) {
            throw ValidationException::withMessages(['training_id' => self::OUTSIDE_DAS]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function formOptions(?TrainingSession $current): array
    {
        $trainings = app(Trainings::class)->options();
        $durations = app(Trainings::class)->moduleDurations(array_map(fn ($t) => $t->id, $trainings));
        $options = array_map(fn ($t) => [
            'value' => $t->id,
            'label' => $t->code === null ? $t->title : "{$t->title} ({$t->code})",
            'hours' => $t->duration_hours,
            // A training with modules gives the session their sum (null: none).
            'modules_hours' => self::modulesHours($durations[$t->id] ?? []),
        ], $trainings);
        if ($current !== null && ! in_array($current->training_id, array_column($options, 'value'), true)) {
            $kept = app(Trainings::class)->find($current->training_id);
            if ($kept !== null) {
                $options[] = ['value' => $kept->id, 'label' => $kept->title.' (désactivée)', 'hours' => $kept->duration_hours, 'modules_hours' => null];
            }
        }

        return [
            'training' => $options,
            'kind' => SessionKind::options(),
            'modality' => SessionListQuery::reference(ReferenceList::TrainingModality, $current === null ? [] : [$current->modality_id]),
            'default_modality' => SessionWriter::defaultModalityId(),
            'location' => SessionLocation::options(),
            'location_companies' => SessionPlace::companyOptions($current?->location_company_id),
            'status' => array_map(fn (SessionStatus $s) => $s->toOption(), SessionStatus::SETTABLE),
            'responsible' => SessionListQuery::responsible($current?->responsible_user_id),
        ];
    }

    /**
     * « 10 h 30 », « à définir » (modules without hours), null without module.
     *
     * @param  list<?string>  $durations
     */
    private static function modulesHours(array $durations): ?string
    {
        $sum = SessionWriter::modulesSum(array_map(fn (?string $h) => ['duration_hours' => $h], $durations));

        return match (true) {
            $sum === false => null,
            $sum === null => SessionModules::UNDEFINED,
            default => SessionModules::label($sum),
        };
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
