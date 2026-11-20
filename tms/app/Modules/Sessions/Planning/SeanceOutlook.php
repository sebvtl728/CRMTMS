<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Connectors\Calendar\IcalFeed;
use App\Modules\Connectors\Calendar\OutlookItem;
use App\Modules\Connectors\Calendar\OutlookSources;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainer;
use Illuminate\Support\Facades\DB;

/**
 * The séances in the Outlook calendar of their trainer (spec 0.8.1 § 4.4),
 * through Connectors\Calendar\OutlookSources (« sessions.seance »,
 * registered by SessionsServiceProvider).
 *
 * find() is the copy a séance should have now: a planned séance (not in
 * the trash) of a module and a session out of the trash, the session not
 * cancelled, whose trainer is active and linked to an account. « <session>
 * - <module> », Paris times, the place of the iCal feed (SeanceCalendar),
 * a link to « Mon planning »; never a trainee, a note nor a price.
 *
 * The writers call touch*() on every change: the push is queued once the
 * transaction is committed (a rolled-back write pushes nothing), one job per
 * séance.
 */
final class SeanceOutlook
{
    public const SOURCE = 'sessions.seance';

    public static function register(): void
    {
        OutlookSources::register(self::SOURCE, self::find(...), self::serves(...), self::connected(...));
    }

    /** An active trainer is linked to $user: « Connecter Outlook » is offered to him. */
    public static function serves(User $user): bool
    {
        return Trainer::query()->where('user_id', $user->getKey())->where('active', true)->exists();
    }

    /**
     * $userId has just connected Outlook: the séances of his trainer from
     * today (Paris) to the horizon of the iCal feed (IcalFeed::DAYS_AFTER).
     */
    public static function connected(string $userId): void
    {
        $trainer = Trainer::query()->where('user_id', $userId)->where('active', true)->first();
        if ($trainer === null) {
            return;
        }
        $today = TrainingSession::today();
        self::touchWhere('trainer_id', $trainer->id, $today->toDateString(), $today->addDays(IcalFeed::DAYS_AFTER)->toDateString());
    }

    public static function find(string $seanceId): ?OutlookItem
    {
        $seance = Seance::query()->find($seanceId);
        if ($seance === null || $seance->isCancelled() || $seance->trainer_id === null) {
            return null;
        }
        $trainer = Trainer::query()->find($seance->trainer_id);
        $session = TrainingSession::query()->find($seance->training_session_id);
        $module = SessionModule::query()->find($seance->session_module_id);
        if ($trainer === null || ! $trainer->active || $trainer->user_id === null
            || $session === null || $session->isCancelled() || $module === null) {
            return null;
        }
        $room = $seance->room_id === null ? null : Room::withTrashed()->find($seance->room_id);
        $label = PlanningRows::sessionLabels([$session])[$session->id] ?? $session->number;

        return new OutlookItem(
            userId: $trainer->user_id,
            subject: $label.' - '.$module->title,
            start: $seance->startsAtInstant(),
            end: $seance->endsAtInstant(),
            location: SeanceCalendar::location($room, $seance, $session),
            body: 'Mon planning : '.route('my-planning.show', ['date' => $seance->date->toDateString()]),
        );
    }

    /** A séance was created, changed, cancelled or deleted. */
    public static function touch(string $seanceId): void
    {
        DB::afterCommit(fn () => OutlookSources::push(self::SOURCE, $seanceId));
    }

    /** The session was cancelled, trashed, restored, renamed or moved: each of its séances with a trainer. */
    public static function touchSession(string $sessionId): void
    {
        self::touchWhere('training_session_id', $sessionId);
    }

    /** The session module was renamed: each of its séances with a trainer. */
    public static function touchModule(string $moduleId): void
    {
        self::touchWhere('session_module_id', $moduleId);
    }

    /** The trainer was linked, unlinked, deactivated or activated: his séances to come. */
    public static function touchTrainer(string $trainerId): void
    {
        self::touchWhere('trainer_id', $trainerId, TrainingSession::today()->toDateString());
    }

    /** The room was renamed or moved: its séances to come with a trainer. */
    public static function touchRoom(string $roomId): void
    {
        self::touchWhere('room_id', $roomId, TrainingSession::today()->toDateString());
    }

    /**
     * After commit, pushes the séances having $column = $id (with a trainer;
     * dated from $from and up to $until, Paris days, when given).
     */
    private static function touchWhere(string $column, string $id, ?string $from = null, ?string $until = null): void
    {
        DB::afterCommit(function () use ($column, $id, $from, $until): void {
            $ids = Seance::query()->where($column, $id)->whereNotNull('trainer_id')
                ->when($from !== null, fn ($q) => $q->where('date', '>=', $from))
                ->when($until !== null, fn ($q) => $q->where('date', '<=', $until))->pluck('id');
            foreach ($ids as $seanceId) {
                OutlookSources::push(self::SOURCE, (string) $seanceId);
            }
        });
    }
}
