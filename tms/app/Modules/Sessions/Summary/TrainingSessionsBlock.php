<?php

namespace App\Modules\Sessions\Summary;

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionRows;
use App\Modules\Sessions\Sessions\SessionStatus;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Sessions » block of the training page (Catalogue\TrainingBlocks): the
 * sessions of the training in the viewer's scope of sessions.sessions.view
 * (never cancelled nor trashed), Paris days. Indicators: upcoming, running,
 * ended over the last 12 months, trainees (enrolments neither cancelled nor
 * waitlisted) of the sessions started in the last 12 months. The list shows
 * the upcoming and running sessions, then the recently ended ones (20 at
 * most). Null without the right (no block).
 */
final class TrainingSessionsBlock
{
    public const LIMIT = 20;

    /**
     * @return array{title: string, data: array<string, mixed>}|null
     */
    public static function for(string $trainingId, User $viewer, ?CarbonImmutable $today = null): ?array
    {
        if (! $viewer->can(Permissions::SESSIONS_VIEW)) {
            return null;
        }
        $today = ($today ?? TrainingSession::today())->setTimezone('Europe/Paris')->startOfDay();
        $day = $today->toDateString();
        $since = $today->subMonthsNoOverflow(12)->toDateString();

        $live = fn (): Builder => SessionsVisibility::sessions(TrainingSession::query(), $viewer)
            ->where('training_sessions.training_id', $trainingId)
            ->whereIn('training_sessions.status', array_map(fn (SessionStatus $s) => $s->value, SessionStatus::SETTABLE));
        $upcoming = fn (): Builder => $live()->where('training_sessions.starts_on', '>', $day);
        $running = fn (): Builder => $live()->where('training_sessions.starts_on', '<=', $day)->where('training_sessions.ends_on', '>=', $day);
        $ended = fn (): Builder => $live()->where('training_sessions.ends_on', '<', $day)->where('training_sessions.ends_on', '>=', $since);

        $trainees = Enrolment::query()
            ->whereIn('training_session_id', $live()->where('training_sessions.starts_on', '<=', $day)->where('training_sessions.starts_on', '>=', $since)->select('training_sessions.id'))
            ->whereNotIn('status', EnrolmentStatus::values([EnrolmentStatus::Cancelled, EnrolmentStatus::Waitlisted]))
            ->count();

        $open = $live()->where('training_sessions.ends_on', '>=', $day)
            ->orderBy('training_sessions.starts_on')->orderBy('training_sessions.number')->limit(self::LIMIT)->get();
        $recent = $open->count() >= self::LIMIT ? collect() : $ended()
            ->orderByDesc('training_sessions.starts_on')->orderByDesc('training_sessions.number')->limit(self::LIMIT - $open->count())->get();

        $rows = array_map(fn (array $row) => [
            'id' => $row['id'],
            'number' => $row['number'],
            'label' => $row['label'],
            'starts_on' => $row['starts_on'],
            'ends_on' => $row['ends_on'],
            'kind_label' => $row['kind_label'],
            'status' => $row['status'],
            'status_label' => $row['status_label'],
            'href' => route('sessions.show', $row['id'], false),
        ], SessionRows::rows($open->concat($recent), $today));

        return [
            'title' => 'Sessions',
            'data' => [
                'indicators' => [
                    'upcoming' => $upcoming()->count(),
                    'running' => $running()->count(),
                    'ended' => $ended()->count(),
                    'trainees' => $trainees,
                ],
                'sessions' => $rows,
                'can_create' => $viewer->can('create', TrainingSession::class),
                'create_href' => route('sessions.create', ['training' => $trainingId], false),
            ],
        ];
    }
}
