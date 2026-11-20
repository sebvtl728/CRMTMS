<?php

namespace App\Modules\Sessions\FollowUps;

use App\Modules\Crm\Public\Activities;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\FollowUpSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The commercial reactivation call (lot 1e): N months after the end of a
 * dossier (its last session not cancelled; 6 and 12 by default, CRM
 * settings), one call « à faire » on the dossier's account (the entreprise,
 * else the particulier; never the employed trainees), for the responsible
 * of that fiche, else the dossier responsible. Nothing when the client
 * already buys again (an open opportunity, a newer live dossier): the due
 * date is then recorded as skipped and never planned later. Same catch-up
 * window and once-only rule as TrainerFollowUps.
 */
final class Reactivations
{
    public function __construct(private readonly Fiches $fiches) {}

    /** @return int the calls planned */
    public function run(CarbonImmutable $today): int
    {
        $today = CarbonImmutable::parse($today->toDateString(), 'Europe/Paris');
        $planned = 0;
        foreach (FollowUpSettings::reactivationMonths() as $months) {
            $planned += $this->runFor($months, $today);
        }

        return $planned;
    }

    private function runFor(int $months, CarbonImmutable $today): int
    {
        $lastEnd = $today->subMonthsNoOverflow($months)->toDateString();
        $firstEnd = $today->subDays(TrainerFollowUps::CATCH_UP_DAYS)->subMonthsNoOverflow($months)->toDateString();

        $ends = DB::table('enrolments')
            ->join('training_sessions', 'training_sessions.id', '=', 'enrolments.training_session_id')
            ->where('enrolments.status', '!=', 'cancelled')
            ->where('training_sessions.status', '!=', 'cancelled')->whereNull('training_sessions.deleted_at')
            ->groupBy('enrolments.dossier_id')
            ->selectRaw('enrolments.dossier_id, max(training_sessions.ends_on) as ended_on');
        $rows = DB::table('dossiers')
            ->joinSub($ends, 'ends', 'ends.dossier_id', '=', 'dossiers.id')
            ->where('dossiers.status', '!=', 'cancelled')->whereNull('dossiers.deleted_at')
            ->whereBetween('ends.ended_on', [$firstEnd, $lastEnd])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('follow_up_reminders')
                ->where('follow_up_reminders.kind', FollowUpReminder::REACTIVATION)->where('follow_up_reminders.months', $months)
                ->whereColumn('follow_up_reminders.subject_id', 'dossiers.id'))
            ->orderBy('dossiers.id')
            ->get(['dossiers.id', 'dossiers.number', 'dossiers.account_organisation_id', 'dossiers.account_person_id', 'dossiers.responsible_user_id', 'ends.ended_on']);

        $planned = 0;
        foreach ($rows as $row) {
            [$type, $ficheId] = $row->account_organisation_id !== null ? ['organisation', $row->account_organisation_id] : ['person', (string) $row->account_person_id];
            $reason = null;
            $userId = null;
            if ($this->fiches->hasOpenOpportunity($type, $ficheId) || self::hasNewerDossier($row, $type, $ficheId)) {
                $reason = 'rachat_en_cours';
            } else {
                $userId = $this->fiches->responsibleUser($type, $ficheId) ?? self::active($row->responsible_user_id);
                $reason = $userId === null ? 'aucun_destinataire' : null;
            }
            DB::transaction(function () use ($row, $type, $ficheId, $userId, $reason, $months, &$planned): void {
                $activityId = $userId === null ? null : Activities::planCall($type, $ficheId, $userId, self::subject($row, $months),
                    CarbonImmutable::parse($row->ended_on, 'Europe/Paris')->addMonthsNoOverflow($months)->setTime(9, 0));
                FollowUpReminder::record(FollowUpReminder::REACTIVATION, $row->id, $months, $activityId,
                    $activityId === null ? ($reason ?? 'fiche_indisponible') : null);
                $planned += $activityId === null ? 0 : 1;
            });
        }

        return $planned;
    }

    /** « Réactivation : HACCP terminée il y a 6 mois (D-2026-0005) ». */
    private static function subject(object $row, int $months): string
    {
        $labels = DB::table('dossier_lines')->where('dossier_id', $row->id)->where('kind', 'training')->orderBy('position')->pluck('label')->all();
        $what = $labels === [] ? 'formation' : implode(', ', $labels);

        return "Réactivation : {$what} ".(count($labels) > 1 ? 'terminées' : 'terminée')." il y a {$months} mois ({$row->number})";
    }

    /** Another live dossier of the client created after the end of this one. */
    private static function hasNewerDossier(object $row, string $type, string $ficheId): bool
    {
        return DB::table('dossiers')->where($type === 'organisation' ? 'account_organisation_id' : 'account_person_id', $ficheId)
            ->where('id', '!=', $row->id)->where('status', '!=', 'cancelled')->whereNull('deleted_at')
            ->where('created_at', '>', CarbonImmutable::parse($row->ended_on, 'Europe/Paris')->endOfDay())->exists();
    }

    private static function active(?string $userId): ?string
    {
        return $userId !== null && DB::table('users')->where('id', $userId)->whereNull('deactivated_at')->exists() ? $userId : null;
    }
}
