<?php

namespace App\Modules\Sessions\Sessions;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsVisibility;
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
 * « Sessions » screens: list with filters, monthly calendar, session page,
 * create / edit form, cancellation (with a reason), trash and restore.
 * Validation and writing: SessionWriter; rights and scopes: SessionPolicy.
 */
class SessionController extends Controller
{
    public const OUTSIDE_DAS = 'Cette formation n\'est pas dans vos DAS.';

    /** French month names (no locale dependency). */
    public const MONTHS = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public function __construct(private readonly SessionWriter $writer) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = SessionListQuery::filters($request->query());
        $today = TrainingSession::today();

        $page = SessionListQuery::build($filters, $user, $today)->paginate(25)->withQueryString();

        return Inertia::render('sessions/sessions/index', [
            'sessions' => [...$page->toArray(), 'data' => SessionRows::rows($page->items(), $today)],
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
            'kind' => in_array($request->query('kind'), ['intra', 'inter'], true) ? (string) $request->query('kind') : null,
            'das' => SessionListQuery::filters(['das' => $request->query('das')])['das'],
        ];

        return Inertia::render('sessions/sessions/calendar', [
            'month' => $first->format('Y-m'),
            'label' => self::MONTHS[$first->month].' '.$first->year,
            'prev' => $first->subMonth()->format('Y-m'),
            'next' => $first->addMonth()->format('Y-m'),
            'today' => TrainingSession::today()->toDateString(),
            'sessions' => SessionRows::rows(SessionListQuery::month($first, $user, $filters)->get(), TrainingSession::today()),
            'filters' => $filters,
            'options' => ['kind' => SessionKind::options(), 'das' => SessionListQuery::reference(ReferenceList::Das)],
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
            'status' => $request->session()->get('status'),
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
                'cancelled' => $s->isCancelled(),
                'training_id' => $s->training_id,
                'kind' => $s->kind,
                'organisation' => $organisation === null ? null : ['id' => $s->organisation_id, 'label' => $organisation['label']],
                'starts_on' => $s->starts_on->toDateString(),
                'ends_on' => $s->ends_on->toDateString(),
                'planned_hours' => SessionRows::hours($s->planned_hours),
                'location_kind' => $s->location_kind,
                'location_text' => $s->location_text,
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

        DB::transaction(fn () => $trainingSession->delete());

        return to_route('sessions.index')->with('status', "Session {$trainingSession->number} mise à la corbeille.");
    }

    public function restore(TrainingSession $trainingSession): RedirectResponse
    {
        Gate::authorize('restore', $trainingSession);

        $this->writer->restore($trainingSession);

        return to_route('sessions.show', $trainingSession)->with('status', 'Session restaurée.');
    }

    /**
     * Entreprises of the organisme for an intra session (identity only).
     */
    public function organisations(Request $request, Fiches $fiches): JsonResponse
    {
        Gate::authorize('create', TrainingSession::class);
        $q = $request->query('q');

        return response()->json(['entreprises' => is_string($q) && mb_strlen(trim($q)) >= 2 ? $fiches->searchOrganisations(mb_substr($q, 0, 100)) : []]);
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
     * @return array<string, list<array{value: string, label: string}>>
     */
    private static function formOptions(?TrainingSession $current): array
    {
        $trainings = app(Trainings::class)->options();
        $options = array_map(fn ($t) => ['value' => $t->id, 'label' => $t->code === null ? $t->title : "{$t->title} ({$t->code})", 'hours' => $t->duration_hours], $trainings);
        if ($current !== null && ! in_array($current->training_id, array_column($options, 'value'), true)) {
            $kept = app(Trainings::class)->find($current->training_id);
            if ($kept !== null) {
                $options[] = ['value' => $kept->id, 'label' => $kept->title.' (désactivée)', 'hours' => $kept->duration_hours];
            }
        }

        return [
            'training' => $options,
            'kind' => SessionKind::options(),
            'location' => SessionLocation::options(),
            'status' => array_map(fn (SessionStatus $s) => $s->toOption(), SessionStatus::SETTABLE),
            'responsible' => SessionListQuery::responsible($current?->responsible_user_id),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
