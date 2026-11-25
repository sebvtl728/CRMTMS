<?php

namespace App\Modules\Sessions\Planning;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Months;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The « Planning individuel » of a trainee (spec 2026-11-24 § 9): read and
 * written with sessions.sessions.update on his session (EnrolmentPolicy);
 * an enrolment out of the view scope is a 404 at binding.
 */
class IndividualPlanningController extends Controller
{
    public function __construct(private readonly IndividualPlanningWriter $writer) {}

    public function show(Enrolment $enrolment): JsonResponse
    {
        Gate::authorize('update', $enrolment);

        return response()->json(IndividualPlanningView::of($enrolment, true))->header('Cache-Control', 'no-store, private');
    }

    public function exempt(Request $request, Enrolment $enrolment): RedirectResponse
    {
        Gate::authorize('update', $enrolment);
        $data = Validator::make($request->all(), [
            'seance_ids' => ['required_without:module_id', 'array', 'max:500'],
            'seance_ids.*' => ['string'],
            'module_id' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:300'],
        ], [
            'seance_ids.required_without' => 'Choisissez les séances ou le module.',
            'reason.max' => 'Le motif ne doit pas dépasser 300 caractères.',
        ])->validate();
        $reason = isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null;
        if (isset($data['module_id']) && is_string($data['module_id'])) {
            $this->writer->exemptModule($enrolment, $data['module_id'], $reason);
        } else {
            /** @var list<string> $ids */
            $ids = array_values($data['seance_ids']);
            $this->writer->exemptSeances($enrolment, $ids, $reason);
        }

        return back()->with('status', 'Dispense enregistrée.');
    }

    /**
     * « Ajouter des séances »: the sessions the user sees (not cancelled,
     * not his own; « q » on number or title, 20 at most) and, for the one
     * chosen (« session »), its live séances he may take: in his dates, an
     * open month, not in his planning yet, a clash with one of his flagged.
     */
    public function available(Request $request, Enrolment $enrolment): JsonResponse
    {
        Gate::authorize('update', $enrolment);
        $user = $request->user();
        assert($user instanceof User);
        $q = $request->query('q');
        $sessions = SessionsVisibility::sessions(TrainingSession::query(), $user)
            ->where('training_sessions.id', '!=', $enrolment->training_session_id)
            ->where('training_sessions.status', '!=', 'cancelled')
            ->when(is_string($q) && trim($q) !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('training_sessions.number', 'ilike', '%'.addcslashes(trim((string) $q), '%_\\').'%')
                ->orWhere('training_sessions.title', 'ilike', '%'.addcslashes(trim((string) $q), '%_\\').'%')))
            ->orderByDesc('training_sessions.starts_on')->limit(20)->get();
        $trainings = app(Trainings::class)->findMany($sessions->pluck('training_id')->unique()->values()->all());

        $seances = [];
        $chosen = $request->query('session');
        if (is_string($chosen) && $sessions->contains('id', $chosen)) {
            $his = collect(IndividualPlanning::seancesOf(collect([$enrolment]))[$enrolment->id] ?? []);
            $taken = EnrolmentAdjustment::query()->where('enrolment_id', $enrolment->id)->whereNotNull('seance_id')->pluck('seance_id')->all();
            $titles = SessionModule::query()->where('training_session_id', $chosen)->pluck('title', 'id');
            $closed = AttendanceClosure::query()->where('training_session_id', $chosen)->whereNotNull('closed_at')->get()
                ->map(fn (AttendanceClosure $c) => Months::key($c->month))->all();
            $seances = Seance::query()->where('training_session_id', $chosen)->where('status', Seance::PLANNED)
                ->whereNotIn('id', $taken)
                ->when($enrolment->entered_on !== null, fn ($query) => $query->where('date', '>=', $enrolment->entered_on?->toDateString()))
                ->when($enrolment->left_on !== null, fn ($query) => $query->where('date', '<=', $enrolment->left_on?->toDateString()))
                ->orderBy('date')->orderBy('starts_at')->limit(300)->get()
                ->reject(fn (Seance $s) => in_array($s->date->format('Y-m'), $closed, true))
                ->map(fn (Seance $s) => [
                    'id' => $s->id,
                    'date' => $s->date->toDateString(),
                    'start' => $s->start(),
                    'end' => $s->end(),
                    'module' => $titles[$s->session_module_id] ?? null,
                    'conflict' => $his->contains(fn (PlannedSeance $p) => $p->seance->date->equalTo($s->date) && $p->start < $s->end() && $s->start() < $p->end),
                ])->values()->all();
        }

        return response()->json([
            'sessions' => $sessions->map(fn (TrainingSession $s) => ['id' => $s->id, 'label' => $s->label($trainings[$s->training_id]->title ?? null)])->values()->all(),
            'seances' => $seances,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function add(Request $request, Enrolment $enrolment): RedirectResponse
    {
        Gate::authorize('update', $enrolment);
        $data = Validator::make($request->all(), [
            'seance_ids' => ['required', 'array', 'min:1', 'max:200'],
            'seance_ids.*' => ['string'],
            'reason' => ['nullable', 'string', 'max:300'],
        ], [
            'seance_ids.*' => 'Choisissez les séances à ajouter.',
            'reason.max' => 'Le motif ne doit pas dépasser 300 caractères.',
        ])->validate();
        $user = $request->user();
        assert($user instanceof User);
        // Only séances of sessions the user sees.
        /** @var list<string> $ids */
        $ids = array_values($data['seance_ids']);
        $visible = Seance::query()->whereIn('id', array_filter($ids, fn (string $id) => Str::isUuid($id)))
            ->whereIn('training_session_id', SessionsVisibility::sessions(TrainingSession::query(), $user)->select('training_sessions.id'))->count();
        if ($visible !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['seance_ids' => IndividualPlanningWriter::NOT_HIS]);
        }
        $this->writer->addSeances($enrolment, $ids, isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null);

        return back()->with('status', count($ids) > 1 ? 'Séances ajoutées.' : 'Séance ajoutée.');
    }

    public function hours(Request $request, Enrolment $enrolment, string $seanceId): RedirectResponse
    {
        Gate::authorize('update', $enrolment);
        $data = Validator::make($request->all(), [
            'starts_at' => ['required', 'string'],
            'ends_at' => ['required', 'string'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
        ], [
            'starts_at.required' => 'Indiquez l\'heure d\'arrivée.',
            'ends_at.required' => 'Indiquez l\'heure de départ.',
            'break_minutes.*' => 'Pause invalide.',
        ])->validate();
        $this->writer->setOwnHours($enrolment, $seanceId, (string) $data['starts_at'], (string) $data['ends_at'], (int) ($data['break_minutes'] ?? 0));

        return back()->with('status', 'Horaires enregistrés.');
    }

    public function clearHours(Enrolment $enrolment, string $seanceId): RedirectResponse
    {
        Gate::authorize('update', $enrolment);
        $this->writer->clearOwnHours($enrolment, $seanceId);

        return back()->with('status', 'Horaires de la séance rétablis.');
    }

    public function remove(Enrolment $enrolment, string $adjustmentId): RedirectResponse
    {
        Gate::authorize('update', $enrolment);
        $adjustment = EnrolmentAdjustment::query()->where('enrolment_id', $enrolment->id)->whereKey($adjustmentId)->firstOrFail();
        $this->writer->remove($adjustment);

        return back()->with('status', 'Planning mis à jour.');
    }
}
