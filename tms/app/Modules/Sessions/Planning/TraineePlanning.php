<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Resources\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The planning of a trainee on his person fiche (« Formations suivies »):
 * for each enrolment (neither cancelled nor waitlisted), his next séances
 * from today (planned, between his entry and leaving days) and the
 * placements not over yet, at his own dates when he has some, with his
 * host. One query per kind of data for all his enrolments.
 */
final class TraineePlanning
{
    /** Next séances listed per enrolment; the others are counted. */
    public const UPCOMING = 10;

    private const WEEKDAYS = [1 => 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.'];

    /**
     * @param  Collection<int, Enrolment>  $enrolments
     * @return array<string, list<string>> enrolment id => lines, in order
     */
    public static function details(Collection $enrolments, CarbonImmutable $today): array
    {
        $enrolments = $enrolments->reject(fn (Enrolment $e) => in_array($e->status, [EnrolmentStatus::Cancelled, EnrolmentStatus::Waitlisted], true))->values();
        $sessionIds = $enrolments->pluck('training_session_id')->unique()->values()->all();
        if ($sessionIds === []) {
            return [];
        }
        $day = $today->toDateString();
        // His individual planning: exempted séances left out, added ones in, at his hours.
        $planned = IndividualPlanning::seancesOf($enrolments, $day);
        $all = collect($planned)->flatten(1)->map(fn (PlannedSeance $p) => $p->seance);
        $modules = SessionModule::withTrashed()->whereIn('id', $all->pluck('session_module_id')->unique()->values()->all())->pluck('title', 'id');
        $rooms = Room::withTrashed()->whereIn('id', $all->pluck('room_id')->filter()->unique()->values()->all())->pluck('name', 'id');
        $periods = PlacementPeriod::query()->with('hosts')->whereIn('training_session_id', $sessionIds)
            ->orderBy('starts_on')->orderBy('id')->get()->groupBy('training_session_id');
        $hosts = app(Fiches::class)->organisationLabels($periods->flatten(1)->flatMap(fn (PlacementPeriod $p) => $p->hosts)
            ->whereIn('enrolment_id', $enrolments->pluck('id')->all())->pluck('organisation_id')->filter()->map(fn ($id) => (string) $id)->values()->all());

        $out = [];
        foreach ($enrolments as $enrolment) {
            $his = collect($planned[$enrolment->id] ?? []);
            $lines = $his->take(self::UPCOMING)->map(fn (PlannedSeance $p) => implode(' · ', array_filter([
                self::WEEKDAYS[$p->seance->date->isoWeekday()].' '.$p->seance->date->format('d/m/Y'),
                $p->start.'–'.$p->end,
                $modules[$p->seance->session_module_id] ?? null,
                $p->seance->room_id !== null ? ($rooms[$p->seance->room_id] ?? null) : $p->seance->place_text,
            ])))->all();
            if ($his->count() > self::UPCOMING) {
                $more = $his->count() - self::UPCOMING;
                $lines[] = $more > 1 ? "… et {$more} autres séances à venir" : '… et 1 autre séance à venir';
            }
            foreach ($periods[$enrolment->training_session_id] ?? [] as $period) {
                $dates = PlacementPeriods::datesFor($period, $enrolment->id);
                if ($dates['to'] < $day) {
                    continue;
                }
                $host = $period->hosts->firstWhere('enrolment_id', $enrolment->id);
                $organisation = $host?->organisation_id === null ? null : ($hosts[$host->organisation_id] ?? null);
                $lines[] = $period->label.' du '.WorkingDays::day($dates['from'])->format('d/m/Y').' au '.WorkingDays::day($dates['to'])->format('d/m/Y')
                    .($organisation === null ? '' : ' · chez '.$organisation['label'].($organisation['trashed'] ? ' (supprimée)' : ''));
            }
            $out[$enrolment->id] = $lines;
        }

        return $out;
    }
}
