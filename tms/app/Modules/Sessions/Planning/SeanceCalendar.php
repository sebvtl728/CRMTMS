<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Connectors\Calendar\CalendarEvent;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Sessions\SessionKind;
use App\Modules\Sessions\Sessions\SessionLocation;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The séances a user's iCal feed carries (Connectors\Calendar\CalendarSources,
 * registered by SessionsServiceProvider): those of the trainer linked to
 * him (whatever his rights: his own work, no personal data), and those of
 * the sessions he is responsible for within the scope of his
 * sessions.seances.view; sessions and séances in the trash left out, cancelled
 * ones published as cancelled (so subscribed calendars drop them).
 *
 * An event: « <session> — <module> », the room (and its address), else
 * the free place, else the address of the session (not of an individual
 * session: maybe the trainee's home); never a trainee, a
 * note nor a cancellation reason. Paris wall-clock times (Seance::instant);
 * the length is the real time from start to end (the break inside).
 */
final class SeanceCalendar
{
    /** Séances of one feed, at most. */
    public const LIMIT = 2000;

    public static function serves(User $user): bool
    {
        return Trainers::forUser($user) !== null
            || ($user->can(Permissions::SEANCES_VIEW) && self::responsibleSeances($user)->exists());
    }

    /**
     * Séances of the sessions $user is responsible for, within the scope of
     * his sessions.seances.view (a responsible out of his DAS gets nothing;
     * on « Ses séances », those he animates only).
     *
     * @return Builder<Seance>
     */
    private static function responsibleSeances(User $user): Builder
    {
        return SessionsVisibility::seances(
            Seance::query()->whereIn('training_session_id', TrainingSession::query()->where('responsible_user_id', $user->getKey())->select('id')),
            $user,
        );
    }

    /**
     * @return list<CalendarEvent>
     */
    public static function eventsFor(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $trainer = Trainers::forUser($user);
        $responsible = $user->can(Permissions::SEANCES_VIEW);
        if ($trainer === null && ! $responsible) {
            return [];
        }
        $paris = fn (CarbonImmutable $at): string => $at->setTimezone('Europe/Paris')->toDateString();

        $seances = Seance::query()
            ->where(fn (Builder $q) => $q
                ->when($trainer, fn (Builder $q, $t) => $q->orWhere('trainer_id', $t->id))
                ->when($responsible, fn (Builder $q) => $q->orWhereIn('id', self::responsibleSeances($user)->select('seances.id'))))
            ->whereIn('training_session_id', TrainingSession::query()->select('id'))
            ->where('date', '>=', $paris($from))->where('date', '<', $paris($to))
            ->orderBy('date')->orderBy('starts_at')->orderBy('id')
            ->limit(self::LIMIT)->get();
        if ($seances->isEmpty()) {
            return [];
        }

        $sessions = TrainingSession::query()->whereIn('id', $seances->pluck('training_session_id')->unique()->values()->all())->get();
        $labels = PlanningRows::sessionLabels($sessions);
        $sessions = $sessions->keyBy('id');
        $modules = SessionModule::withTrashed()->whereIn('id', $seances->pluck('session_module_id')->unique()->values()->all())->pluck('title', 'id');
        $rooms = Room::withTrashed()->whereIn('id', $seances->pluck('room_id')->filter()->unique()->values()->all())->get()->keyBy('id');

        $events = [];
        foreach ($seances as $seance) {
            $session = $sessions->get($seance->training_session_id);
            $start = $seance->startsAtInstant();
            $room = $seance->room_id === null ? null : $rooms->get($seance->room_id);
            $mine = $trainer !== null && $seance->trainer_id === $trainer->id;
            $events[] = new CalendarEvent(
                uid: 'seance-'.$seance->id,
                title: ($labels[$seance->training_session_id] ?? 'Session').' — '.($modules[$seance->session_module_id] ?? 'Séance'),
                start: $start,
                minutes: (int) $start->diffInMinutes($seance->endsAtInstant()),
                location: self::location($room, $seance, $session),
                description: null,
                url: $mine ? route('my-planning.show', ['date' => $seance->date->toDateString()]) : route('sessions.show', $seance->training_session_id),
                updatedAt: CarbonImmutable::instance($seance->updated_at ?? now()),
                cancelled: $seance->isCancelled() || ($session?->isCancelled() ?? false),
            );
        }

        return $events;
    }

    private static function location(?Room $room, Seance $seance, ?TrainingSession $session): ?string
    {
        if ($room !== null) {
            return $room->address === null || trim($room->address) === '' ? $room->name : $room->name.', '.$room->address;
        }
        if ($seance->place_text !== null && trim($seance->place_text) !== '') {
            return $seance->place_text;
        }

        // Never the address of an individual session: it may be the trainee's home.
        return $session !== null && $session->kind !== SessionKind::Individual->value && $session->location_kind === SessionLocation::Address->value
            ? $session->location_text
            : null;
    }
}
