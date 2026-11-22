<?php

namespace App\Modules\Sessions\Todo;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Todo\TodoSources;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionStatus;
use App\Modules\Sessions\Sessions\StartingSoon;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Summary\SessionChecklist;
use Illuminate\Database\Eloquent\Builder;

/** What the Sessions module declares to « À traiter » (2026-11-22). */
final class SessionsTodo
{
    public static function register(): void
    {
        $manager = fn (User $u): bool => Scopes::of($u, Permissions::ATTENDANCE_MANAGE) !== AccessScope::Trainer;

        TodoSources::register('sessions.starting_soon', 'Sessions', 'Sessions qui démarrent dans 7 jours avec des étapes à faire', Permissions::SESSIONS_VIEW,
            function (User $u): int {
                $today = TrainingSession::today();
                $sessions = SessionsVisibility::sessions(TrainingSession::query(), $u)
                    ->where('training_sessions.status', '<>', SessionStatus::Cancelled->value)
                    ->whereBetween('training_sessions.starts_on', [$today->toDateString(), $today->addDays(StartingSoon::DAYS)->toDateString()])->get();

                return count(array_filter(SessionChecklist::forMany($sessions, $u, $today), fn (array $cards) => SessionChecklist::pending($cards) > 0));
            },
            fn (User $u): string => '/sessions');

        TodoSources::register('sessions.catch_ups', 'Sessions', 'Rattrapages d\'émargement à confirmer', Permissions::ATTENDANCE_MANAGE,
            fn (User $u): int => AttendanceSignature::query()->where('kind', '!=', AttendanceSignature::KIND_ON_TIME)
                ->whereNull('confirmed_at')->whereNull('refused_at')
                ->whereIn('slot_id', SignSlot::query()->whereIn('training_session_id', self::managed($u))->select('id'))->count(),
            fn (User $u): string => '/sessions', $manager);

        TodoSources::register('sessions.unvalidated_slots', 'Sessions', 'Demi-journées passées d\'émargement non validées', Permissions::ATTENDANCE_MANAGE,
            fn (User $u): int => SignSlot::query()->where('status', '!=', SignSlot::VALIDATED)
                ->where('date', '<', TrainingSession::today()->toDateString())
                ->whereIn('training_session_id', self::managed($u))->count(),
            fn (User $u): string => '/sessions', $manager);
    }

    /**
     * The live sessions whose attendance the user manages.
     *
     * @return Builder<TrainingSession>
     */
    private static function managed(User $user): Builder
    {
        return SessionsVisibility::sessions(TrainingSession::query(), $user, Permissions::ATTENDANCE_MANAGE)
            ->where('training_sessions.status', '<>', SessionStatus::Cancelled->value)->select('training_sessions.id');
    }
}
