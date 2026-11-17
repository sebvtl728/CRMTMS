<?php

namespace App\Modules\Sessions\Planning;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Sessions\SessionListQuery;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\Trainers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sessions → Planning: every séance the viewer's sessions.seances.view
 * scope reaches (« Ses séances »: his own) in a month or a week, sessions in
 * the trash left out; filters trainer, room, session and DAS (of the
 * session; the trainer filter then lists the trainers of that DAS).
 */
class PlanningController extends Controller
{
    public function index(Request $request, Fiches $fiches): Response
    {
        $user = $request->user();
        assert($user instanceof User);
        $period = PlanningPeriod::fromRequest($request);
        $filters = [
            'trainer' => self::id($request->query('trainer')),
            'room' => self::id($request->query('room')),
            'session' => self::id($request->query('session')),
            'das' => self::id($request->query('das')),
        ];
        $trainerScope = Scopes::of($user, Permissions::SEANCES_VIEW) === AccessScope::Trainer;

        $inPeriod = self::inPeriod(SessionsVisibility::seances(Seance::query(), $user), $period);
        $sessionIds = $inPeriod->clone()->distinct()->pluck('training_session_id')->all();

        $query = $inPeriod
            ->when($filters['trainer'], fn (Builder $q, string $id) => $q->where('trainer_id', $id))
            ->when($filters['room'], fn (Builder $q, string $id) => $q->where('room_id', $id))
            ->when($filters['session'], fn (Builder $q, string $id) => $q->where('training_session_id', $id))
            ->when($filters['das'], fn (Builder $q, string $id) => $q->whereIn('training_session_id', TrainingSession::query()->where('das_id', $id)->select('id')));
        $seances = $query->orderBy('date')->orderBy('starts_at')->orderBy('id')->limit(PlanningRows::LIMIT)->get();

        return Inertia::render('sessions/planning', [
            'period' => $period->toArray(),
            ...$period->shading(),
            'seances' => PlanningRows::rows($seances, $user, ! $trainerScope),
            'filters' => $filters,
            'options' => [
                'trainers' => self::trainerOptions($user, $trainerScope, $filters, $fiches),
                'rooms' => Room::query()->orderBy('name')->get(['id', 'name', 'active'])
                    ->map(fn (Room $r) => ['value' => $r->id, 'label' => $r->active ? $r->name : $r->name.' (inactive)'])->values()->all(),
                'sessions' => self::sessionOptions($sessionIds),
                'das' => SessionListQuery::reference(ReferenceList::Das, [$filters['das']]),
            ],
            'can' => ['my_planning' => Trainers::forUser($user) !== null],
        ]);
    }

    /**
     * Séances of live sessions on the days of the period.
     *
     * @param  Builder<Seance>  $query
     * @return Builder<Seance>
     */
    public static function inPeriod(Builder $query, PlanningPeriod $period): Builder
    {
        return $query->whereBetween('date', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereIn('training_session_id', TrainingSession::query()->select('id'));
    }

    /**
     * Active trainers (of the DAS filtered), the filtered one kept; a trainer
     * sees himself only.
     *
     * @param  array{trainer: string|null, das: string|null}  $filters
     * @return list<array{value: string, label: string}>
     */
    private static function trainerOptions(User $user, bool $trainerScope, array $filters, Fiches $fiches): array
    {
        if ($trainerScope) {
            $own = Trainers::forUser($user);
            $trainers = $own === null ? collect() : collect([$own]);
        } else {
            $trainers = Trainers::ofDas($filters['das'])
                ->where(fn (Builder $q) => $q->where('active', true)->when($filters['trainer'], fn (Builder $q, string $id) => $q->orWhere('trainers.id', $id)))
                ->get(['trainers.id', 'trainers.person_id']);
        }
        $labels = $fiches->personLabels($trainers->pluck('person_id')->map(fn ($id) => (string) $id)->all());

        return $trainers->map(fn (Trainer $t) => ['value' => $t->id, 'label' => $labels[$t->person_id]['label'] ?? 'Formateur'])
            ->sortBy(fn (array $o) => mb_strtolower($o['label']).$o['value'])->values()->all();
    }

    /**
     * The sessions holding a séance of the period (within the scope), by label.
     *
     * @param  list<string>  $ids
     * @return list<array{value: string, label: string}>
     */
    private static function sessionOptions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $labels = PlanningRows::sessionLabels(TrainingSession::query()->whereIn('id', $ids)->get());

        return collect($labels)->map(fn (string $label, string $id) => ['value' => $id, 'label' => $label])
            ->sortBy(fn (array $o) => mb_strtolower($o['label']))->values()->all();
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
