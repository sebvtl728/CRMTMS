<?php

namespace App\Modules\Sessions\Planning;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Resources\ClosurePeriods;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Facades\DB;

/**
 * « Générer les séances » of a module dated in séances: every chosen day of
 * the week of the period, every slot, except public holidays, closures of
 * the organisme and the common dates of the session's placements (own
 * dates of a trainee: an alert only). Run again on another or an
 * overlapping period, it adds séances and never touches existing ones: a
 * séance of the same module, day and start (cancelled ones included) is
 * not created again, nor a slot overlapping a planned séance of the
 * session, whatever its module (Review Focus 1). Hours are wall clock in Paris.
 */
final class SeanceGenerator
{
    public const INTERVAL = 'Ce module est daté par un intervalle : passez-le en séances pour générer ses séances.';

    public function preview(SessionModule $module, GenerationRequest $request): GenerationPreview
    {
        self::assertSeances($module);
        $session = TrainingSession::withTrashed()->findOrFail($module->training_session_id);
        Seances::assertSessionOpen($session);

        $closed = WorkingDays::closedDays(ClosurePeriods::between(WorkingDays::day($request->from), WorkingDays::day($request->to)), $request->from, $request->to);
        $holidays = PublicHolidays::between($request->from, $request->to);
        $placement = [];
        foreach (PlacementPeriod::query()->where('training_session_id', $session->id)->where('starts_on', '<=', $request->to)
            ->where('ends_on', '>=', $request->from)->orderBy('starts_on')->get() as $period) {
            for ($d = WorkingDays::day(max($request->from, $period->starts_on->toDateString())); $d->toDateString() <= min($request->to, $period->ends_on->toDateString()); $d = $d->addDay()) {
                $placement[$d->toDateString()] ??= $period->label;
            }
        }
        $existing = [];
        foreach (Seance::query()->where('session_module_id', $module->id)->whereBetween('date', [$request->from, $request->to])->get(['date', 'starts_at']) as $s) {
            $existing[$s->date->toDateString().' '.$s->start()] = true;
        }
        // Planned séances of the session, whatever their module: a new slot never overlaps one.
        $taken = [];
        foreach (Seance::query()->where('training_session_id', $session->id)->where('status', Seance::PLANNED)
            ->whereBetween('date', [$request->from, $request->to])->get(['date', 'starts_at', 'ends_at']) as $s) {
            $taken[$s->date->toDateString()][] = [$s->start(), $s->end()];
        }
        $overlaps = function (string $date, array $slot) use ($taken): bool {
            foreach ($taken[$date] ?? [] as [$start, $end]) {
                if ($slot['start'] < $end && $slot['end'] > $start) {
                    return true;
                }
            }

            return false;
        };

        // A closed month of the session (attendance closure) takes no new séance.
        $open = [];
        $monthOpen = function (string $date) use (&$open, $session): bool {
            return $open[substr($date, 0, 7)] ??= Seances::isMonthOpen($session, $date);
        };

        $already = (int) Seance::query()->where('session_module_id', $module->id)->where('status', Seance::PLANNED)->sum('duration_minutes');
        // « Jusqu'aux heures prévues »: stop once the planned hours of the module are reached.
        $remaining = $request->untilPlanned ? max(0, (int) $module->planned_minutes - $already) : null;
        $added = 0;
        $keptCount = 0;

        $rows = [];
        for ($day = WorkingDays::day($request->from); $day->toDateString() <= $request->to; $day = $day->addDay()) {
            if ($remaining !== null && ($added >= $remaining || $keptCount >= GenerationRequest::MAX)) {
                break;
            }
            if (! in_array($day->isoWeekday(), $request->weekdays, true)) {
                continue;
            }
            $date = $day->toDateString();
            foreach ($request->slots as $slot) {
                // A slot of some days only (« another timetable on Fridays »).
                if ($slot['days'] !== [] && ! in_array($day->isoWeekday(), $slot['days'], true)) {
                    continue;
                }
                $reason = match (true) {
                    ! $monthOpen($date) => 'Mois clôturé',
                    isset($holidays[$date]) => "Férié : {$holidays[$date]}",
                    isset($closed[$date]) => "Fermeture : {$closed[$date]}",
                    isset($placement[$date]) => "Stage : {$placement[$date]}",
                    isset($existing["{$date} {$slot['start']}"]) => 'Séance déjà planifiée',
                    $overlaps($date, $slot) => 'Séance déjà planifiée sur ce créneau',
                    default => null,
                };
                if ($remaining !== null && $reason === null && $added >= $remaining) {
                    break;
                }
                $minutes = Seances::minutesBetween($slot['start'], $slot['end']) - $slot['break_minutes'];
                $rows[] = [
                    'date' => $date,
                    'start' => $slot['start'],
                    'end' => $slot['end'],
                    'minutes' => $minutes,
                    'skipped_reason' => $reason,
                ];
                if ($reason === null) {
                    $added += $minutes;
                    $keptCount++;
                }
            }
        }

        $kept = array_values(array_filter($rows, fn (array $r) => $r['skipped_reason'] === null));
        $total = array_sum(array_column($kept, 'minutes'));

        $alerts = [];
        $conflicts = Conflicts::forMany(array_map(fn (array $r) => [
            'training_session_id' => $session->id, 'date' => $r['date'], 'start' => $r['start'], 'end' => $r['end'],
            'trainer_id' => $request->trainerId, 'room_id' => $request->roomId,
        ], $kept));
        foreach ($conflicts as $list) {
            foreach ($list as $conflict) {
                $alerts[$conflict->type.'|'.$conflict->message] ??= $conflict;
            }
        }

        return new GenerationPreview($rows, $total, $module->planned_minutes, $already, array_values($alerts), self::warning($module, $already + $total),
            $kept === [] ? null : $kept[count($kept) - 1]['date'],
            $module->planned_minutes !== null && $already + $total >= $module->planned_minutes);
    }

    /**
     * Creates the séances the preview keeps, under the lock of the module
     * (computed again inside it: nothing slips in meanwhile).
     *
     * @return list<Seance>
     */
    public function generate(SessionModule $module, GenerationRequest $request): array
    {
        self::assertSeances($module);
        $session = TrainingSession::withTrashed()->findOrFail($module->training_session_id);
        Seances::assertSessionOpen($session);

        return DB::transaction(function () use ($module, $request, $session): array {
            // The session first (as SessionModules does), then the module: two
            // generations on modules of one session never interleave.
            TrainingSession::withTrashed()->lockForUpdate()->findOrFail($session->id);
            $locked = Seances::lockModule($module->id);
            $created = [];
            foreach ($this->preview($locked, $request)->kept() as $row) {
                Seances::assertMonthOpen($session, $row['date']);
                $created[] = $seance = Seances::insert(new Seance([
                    'training_session_id' => $session->id,
                    'session_module_id' => $locked->id,
                    'date' => $row['date'],
                    'starts_at' => $row['start'],
                    'ends_at' => $row['end'],
                    'break_minutes' => Seances::minutesBetween($row['start'], $row['end']) - $row['minutes'],
                    'trainer_id' => $request->trainerId,
                    'room_id' => $request->roomId,
                    'place_text' => $request->placeText,
                ]));
                SeanceOutlook::touch($seance->id);
            }

            return $created;
        });
    }

    /**
     * The planned total of the module against its planned hours: a warning
     * when they differ, never a refusal.
     */
    public static function warning(SessionModule $module, int $scheduled): ?string
    {
        if ($module->planned_minutes === null) {
            return 'Ce module n\'a pas d\'heures prévues : '.SessionModules::label($scheduled).' planifiées.';
        }
        if ($scheduled === $module->planned_minutes) {
            return null;
        }

        return SessionModules::label($scheduled).' planifiées pour '.SessionModules::label($module->planned_minutes).' prévues sur ce module.';
    }

    private static function assertSeances(SessionModule $module): void
    {
        if ($module->dating_mode !== DatingMode::Seances->value) {
            throw new UserFacingHttpException(409, self::INTERVAL);
        }
    }
}
