<?php

namespace App\Modules\Sessions\FollowUps;

use App\Modules\Catalogue\Trainings;
use App\Modules\Crm\Public\Activities;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Settings\TrainerFollowUp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The trainer's follow-up call (lot 1e): N months (TrainerFollowUp, 3 by
 * default) after the end of a trainee's training (left_on, else the end of
 * the session), one call « à faire » per enrolment followed (enrolled or
 * confirmed, session not cancelled), on the trainee's fiche, for the
 * trainer with an account who taught the most hours of the session (tie:
 * name), else the session responsible, else the dossier responsible.
 * A due date older than CATCH_UP_DAYS is never planned (no burst on the
 * history at the first run); each due date is processed once.
 */
final class TrainerFollowUps
{
    public const CATCH_UP_DAYS = 30;

    public function __construct(private readonly Fiches $fiches, private readonly Trainings $trainings) {}

    /** @return int the calls planned */
    public function run(CarbonImmutable $today): int
    {
        $months = TrainerFollowUp::months();
        if ($months === 0) {
            return 0;
        }
        $today = CarbonImmutable::parse($today->toDateString(), 'Europe/Paris');
        // End dates whose due date (end + months) falls in [today − CATCH_UP_DAYS, today].
        $lastEnd = $today->subMonthsNoOverflow($months)->toDateString();
        $firstEnd = $today->subDays(self::CATCH_UP_DAYS)->subMonthsNoOverflow($months)->toDateString();

        $rows = DB::table('enrolments')
            ->join('training_sessions', 'training_sessions.id', '=', 'enrolments.training_session_id')
            ->join('dossiers', 'dossiers.id', '=', 'enrolments.dossier_id')
            ->whereIn('enrolments.status', ['enrolled', 'confirmed'])
            ->where('training_sessions.status', '!=', 'cancelled')->whereNull('training_sessions.deleted_at')
            ->whereNull('dossiers.deleted_at')
            ->whereRaw('coalesce(enrolments.left_on, training_sessions.ends_on) between ? and ?', [$firstEnd, $lastEnd])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('follow_up_reminders')
                ->where('follow_up_reminders.kind', FollowUpReminder::TRAINER)->where('follow_up_reminders.months', $months)
                ->whereColumn('follow_up_reminders.subject_id', 'enrolments.id'))
            ->orderBy('enrolments.id')
            ->get(['enrolments.id', 'enrolments.person_id', 'enrolments.training_session_id', 'dossiers.responsible_user_id as dossier_responsible',
                'training_sessions.responsible_user_id as session_responsible', 'training_sessions.number', 'training_sessions.title', 'training_sessions.training_id',
                DB::raw('coalesce(enrolments.left_on, training_sessions.ends_on) as ended_on')]);
        if ($rows->isEmpty()) {
            return 0;
        }

        $names = $this->fiches->personLabels($rows->pluck('person_id')->all());
        $titles = $this->trainings->findMany($rows->pluck('training_id')->unique()->values()->all());
        $trainers = self::mainTrainers($rows->pluck('training_session_id')->unique()->values()->all());
        $planned = 0;
        foreach ($rows as $row) {
            $userId = $trainers[$row->training_session_id] ?? self::active($row->session_responsible) ?? self::active($row->dossier_responsible);
            $label = TrainingSession::labelOf($row->title, $titles[$row->training_id]->title ?? null, $row->number);
            $due = CarbonImmutable::parse($row->ended_on, 'Europe/Paris')->addMonthsNoOverflow($months)->setTime(9, 0);
            DB::transaction(function () use ($row, $userId, $label, $due, $months, $names, &$planned): void {
                $activityId = $userId === null ? null : Activities::planCall('person', $row->person_id, $userId,
                    "Suivi à {$months} mois : ".($names[$row->person_id]['label'] ?? '').", {$label}", $due);
                FollowUpReminder::record(FollowUpReminder::TRAINER, $row->id, $months, $activityId,
                    $activityId === null ? ($userId === null ? 'aucun_destinataire' : 'fiche_indisponible') : null);
                $planned += $activityId === null ? 0 : 1;
            });
        }

        return $planned;
    }

    /**
     * Per session, the active user of the trainer who taught the most hours
     * of its séances not cancelled (tie: the user's name).
     *
     * @param  list<string>  $sessionIds
     * @return array<string, string> session id => user id
     */
    private static function mainTrainers(array $sessionIds): array
    {
        $rows = DB::table('seances')
            ->join('trainers', 'trainers.id', '=', 'seances.trainer_id')
            ->join('users', 'users.id', '=', 'trainers.user_id')
            ->whereIn('seances.training_session_id', $sessionIds)
            ->where('seances.status', '!=', 'cancelled')->whereNull('seances.deleted_at')
            ->whereNull('users.deactivated_at')
            ->groupBy('seances.training_session_id', 'users.id', 'users.name')
            ->orderByDesc(DB::raw('sum(seances.duration_minutes)'))->orderBy('users.name')
            ->get(['seances.training_session_id', 'users.id']);
        $out = [];
        foreach ($rows as $row) {
            $out[$row->training_session_id] ??= $row->id;
        }

        return $out;
    }

    private static function active(?string $userId): ?string
    {
        return $userId !== null && DB::table('users')->where('id', $userId)->whereNull('deactivated_at')->exists() ? $userId : null;
    }
}
