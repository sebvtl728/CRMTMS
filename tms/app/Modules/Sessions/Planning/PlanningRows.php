<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Database\Eloquent\Collection;

/**
 * The séances of a planning page (Sessions → Planning, « Mon planning »):
 * each with its session, module, trainer and room, and whether the viewer
 * may enter its attendance; the planners' view (« $planner ») adds alerts,
 * the cancellation reason and the internal note, which a trainer's own
 * view never shows (an alert names trainees of other sessions). One query
 * per kind of data, whatever the number of séances.
 */
final class PlanningRows
{
    /** Séances of one page, at most. */
    public const LIMIT = 1000;

    /**
     * Labels of the sessions: « <intitulé ou formation> (S-…) ».
     *
     * @param  iterable<TrainingSession>  $sessions
     * @return array<string, string> session id => label
     */
    public static function sessionLabels(iterable $sessions): array
    {
        $sessions = collect($sessions);
        $titles = app(Trainings::class)->findMany($sessions->pluck('training_id')->map(fn ($id) => (string) $id)->unique()->values()->all());

        return $sessions->mapWithKeys(fn (TrainingSession $s) => [$s->id => $s->label(isset($titles[$s->training_id]) ? $titles[$s->training_id]->title : null)])->all();
    }

    /**
     * @param  Collection<int, Seance>  $seances
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $seances, User $viewer, bool $planner): array
    {
        if ($seances->isEmpty()) {
            return [];
        }
        $ids = $seances->pluck('id')->all();
        $sessions = TrainingSession::withTrashed()->whereIn('id', $seances->pluck('training_session_id')->unique()->values()->all())->get();
        $labels = self::sessionLabels($sessions);
        $sessions = $sessions->keyBy('id');
        $modules = SessionModule::withTrashed()->whereIn('id', $seances->pluck('session_module_id')->unique()->values()->all())->pluck('title', 'id');
        $trainers = SeanceRows::trainerLabels($seances->pluck('trainer_id')->filter()->unique()->values()->all());
        $rooms = Room::withTrashed()->whereIn('id', $seances->pluck('room_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $attendance = $viewer->can(Permissions::ATTENDANCE_MANAGE)
            ? array_flip(SessionsVisibility::seances(Seance::query()->whereIn('id', $ids), $viewer, Permissions::ATTENDANCE_MANAGE)->pluck('id')->all())
            : [];
        $openable = $viewer->can(Permissions::SESSIONS_VIEW)
            ? array_flip(SessionsVisibility::sessions(TrainingSession::query()->whereIn('id', $sessions->keys()->all()), $viewer)->pluck('id')->all())
            : [];
        $alerts = [];
        if ($planner) {
            $planned = $seances->reject(fn (Seance $s) => $s->isCancelled())->values();
            $alerts = array_combine($planned->pluck('id')->all(), Conflicts::forMany($planned->all()) ?: []) ?: [];
        }

        return $seances->map(function (Seance $s) use ($sessions, $labels, $modules, $trainers, $rooms, $attendance, $openable, $alerts, $planner): array {
            $session = $sessions->get($s->training_session_id);
            $room = $s->room_id === null ? null : $rooms->get($s->room_id);
            $row = [
                'id' => $s->id,
                'date' => $s->date->toDateString(),
                'start' => $s->start(),
                'end' => $s->end(),
                'minutes' => $s->duration_minutes,
                'duration' => SessionModules::label($s->duration_minutes),
                'session' => [
                    'id' => $s->training_session_id,
                    'label' => $labels[$s->training_session_id] ?? 'Session introuvable',
                    'cancelled' => $session?->isCancelled() ?? false,
                    'color' => $session?->color,
                    'href' => isset($openable[$s->training_session_id]) ? route('sessions.show', $s->training_session_id, false) : null,
                ],
                'module' => $modules[$s->session_module_id] ?? '-',
                'trainer' => $s->trainer_id === null ? null : ['id' => $s->trainer_id, 'label' => $trainers[$s->trainer_id] ?? 'Formateur introuvable'],
                'room' => $room === null ? null : ['id' => $room->id, 'name' => $room->name, 'address' => $room->address],
                'place_text' => $s->place_text,
                'status' => $s->status,
                'can_attendance' => ! $s->isCancelled() && isset($attendance[$s->id]),
            ];
            if ($planner) {
                $row['cancel_reason'] = $s->cancel_reason;
                $row['note'] = $s->note;
                $row['alerts'] = array_map(fn (Conflict $c) => $c->toArray(), $alerts[$s->id] ?? []);
            }

            return $row;
        })->values()->all();
    }
}
