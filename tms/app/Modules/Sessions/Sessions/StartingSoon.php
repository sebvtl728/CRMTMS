<?php

namespace App\Modules\Sessions\Sessions;

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;

/**
 * Home card « Sessions qui démarrent dans les 7 jours »: the sessions the
 * viewer sees (sessions.sessions.view and its scope) starting from today to
 * today + 7 (Paris days), neither cancelled nor in the trash, by start date;
 * the places taken of an inter session (enrolled and confirmed trainees).
 * Null without the right (no card).
 */
final class StartingSoon
{
    public const DAYS = 7;

    public const LIMIT = 10;

    /**
     * @return array{total: int, rows: list<array{id: string, number: string, training: string|null, dates: string, kind_label: string, places: string|null, href: string}>}|null
     */
    public static function for(User $viewer, CarbonImmutable $today): ?array
    {
        if (! $viewer->can(Permissions::SESSIONS_VIEW)) {
            return null;
        }
        $today = $today->setTimezone('Europe/Paris')->startOfDay();
        $query = SessionsVisibility::sessions(TrainingSession::query(), $viewer)
            ->where('training_sessions.status', '<>', SessionStatus::Cancelled->value)
            ->whereBetween('training_sessions.starts_on', [$today->toDateString(), $today->addDays(self::DAYS)->toDateString()]);

        $total = $query->clone()->count();
        $sessions = $query->orderBy('training_sessions.starts_on')->orderBy('training_sessions.number')->limit(self::LIMIT)->get();
        $taken = Enrolment::query()->whereIn('training_session_id', $sessions->pluck('id')->all())
            ->whereIn('status', EnrolmentStatus::values([EnrolmentStatus::Enrolled, EnrolmentStatus::Confirmed]))
            ->toBase()->selectRaw('training_session_id, count(*) as taken')->groupBy('training_session_id')
            ->pluck('taken', 'training_session_id');

        $rows = [];
        foreach (SessionRows::rows($sessions, $today) as $row) {
            $starts = CarbonImmutable::parse($row['starts_on']);
            $ends = CarbonImmutable::parse($row['ends_on']);
            $rows[] = [
                'id' => $row['id'],
                'number' => $row['number'],
                'training' => $row['training']['title'] ?? null,
                'dates' => $starts->equalTo($ends) ? 'le '.$starts->format('d/m/Y') : 'du '.$starts->format('d/m/Y').' au '.$ends->format('d/m/Y'),
                'kind_label' => $row['kind_label'],
                'places' => $row['capacity'] === null ? null : ((int) ($taken[$row['id']] ?? 0)).' / '.$row['capacity'].' places',
                'href' => route('sessions.show', $row['id'], false),
            ];
        }

        return ['total' => $total, 'rows' => $rows];
    }
}
