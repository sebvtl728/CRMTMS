<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\MonthlyHour;
use App\Modules\Sessions\Attendance\Months;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Planning\IndividualPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Public service of the realised hours (spec § 4.7), read by the invoicing
 * (0.9): whole minutes, Paris days and months, never a float.
 *
 *  - realised: the attendance minutes of the séances (never a cancelled
 *    one) and the monthly hours of the interval modules, only within the
 *    trainee's entry and leaving days (months for monthly hours): what was
 *    entered outside them (the dates changed afterwards) never counts;
 *  - planned: the planned minutes of the session's modules (a module without
 *    planned hours counts 0), for the whole session: a month never splits
 *    them;
 *  - enrolment_planned_minutes: the planned minutes of the enrolment itself
 *    (its planned hours, integer minutes; empty = the session's total): what
 *    the trainee is due, to bill against (0.9 bills on the realised hours);
 *    planned_minutes stays the total of the session's modules;
 *  - by_module: one entry per module of his session (his part of its planned
 *    minutes), plus one with module_id null for the séances added from other
 *    sessions (catch-ups, individual planning), counted in realised_minutes;
 *  - closed_months: the closed months of the session (« 2026-09 »), only
 *    the asked one when a month is given.
 *
 * A fixed number of queries whatever the number of trainees.
 *
 * @phpstan-type Hours array{planned_minutes: int, enrolment_planned_minutes: int, realised_minutes: int, by_module: list<array{module_id: string|null, planned: int, realised: int}>, closed_months: list<string>}
 */
final class RealisedHours
{
    /**
     * @param  ?string  $month  « 2026-09 »: the realised hours of that Paris month only
     * @return Hours
     */
    public static function forEnrolment(string $enrolmentId, ?string $month = null): array
    {
        $enrolment = Enrolment::query()->findOrFail($enrolmentId);
        $hours = self::compute(new Collection([$enrolment]), self::month($month));

        return $hours[$enrolment->id];
    }

    /**
     * Every trainee of the session who attends (enrolled, confirmed,
     * abandoned) or has hours entered, in order of entry.
     *
     * @return list<array{enrolment_id: string, planned_minutes: int, enrolment_planned_minutes: int, realised_minutes: int, by_module: list<array{module_id: string|null, planned: int, realised: int}>, closed_months: list<string>}>
     */
    public static function forSession(string $sessionId, string $month): array
    {
        $first = self::month($month);
        $enrolments = Enrolment::query()->where('training_session_id', $sessionId)
            ->where(fn (Builder $q) => $q->whereIn('status', Attendances::ATTENDING)
                ->orWhereIn('id', Attendance::query()->select('enrolment_id'))
                ->orWhereIn('id', MonthlyHour::query()->select('enrolment_id')))
            ->orderByRaw('entered_on asc nulls first')->orderBy('created_at')->orderBy('id')->get();
        $hours = self::compute($enrolments, $first);

        return $enrolments->map(fn (Enrolment $e) => ['enrolment_id' => $e->id, ...$hours[$e->id]])->values()->all();
    }

    /**
     * @param  Collection<int, Enrolment>  $enrolments
     * @return array<string, Hours>
     */
    private static function compute(Collection $enrolments, ?CarbonImmutable $first): array
    {
        if ($enrolments->isEmpty()) {
            return [];
        }
        $ids = $enrolments->pluck('id')->all();
        $sessionIds = $enrolments->pluck('training_session_id')->unique()->values()->all();
        $modules = SessionModule::query()->whereIn('training_session_id', $sessionIds)
            ->orderBy('position')->orderBy('id')->get(['id', 'training_session_id', 'planned_minutes'])->groupBy('training_session_id');

        // Attendance minutes per trainee and module, within his days.
        $attendance = Attendance::query()
            ->join('seances', 'seances.id', '=', 'attendances.seance_id')
            ->join('enrolments', 'enrolments.id', '=', 'attendances.enrolment_id')
            ->whereIn('attendances.enrolment_id', $ids)
            ->whereNull('seances.deleted_at')
            // A cancelled séance never counts, whatever minutes its attendance still holds.
            ->where('seances.status', '<>', 'cancelled')
            ->where(fn ($q) => $q->whereNull('enrolments.entered_on')->orWhereColumn('seances.date', '>=', 'enrolments.entered_on'))
            ->where(fn ($q) => $q->whereNull('enrolments.left_on')->orWhereColumn('seances.date', '<=', 'enrolments.left_on'))
            ->when($first, fn ($q, $m) => $q->whereBetween('seances.date', [$m->toDateString(), $m->endOfMonth()->toDateString()]))
            ->groupBy('attendances.enrolment_id', 'seances.session_module_id')
            ->selectRaw('attendances.enrolment_id as enrolment_id, seances.session_module_id as module_id, sum(attendances.minutes)::bigint as minutes')
            ->toBase()->get();

        // Monthly minutes per trainee and module, within his months.
        $monthly = MonthlyHour::query()
            ->join('enrolments', 'enrolments.id', '=', 'monthly_hours.enrolment_id')
            ->whereIn('monthly_hours.enrolment_id', $ids)
            ->where(fn ($q) => $q->whereNull('enrolments.entered_on')->orWhereRaw("monthly_hours.month >= date_trunc('month', enrolments.entered_on)::date"))
            ->where(fn ($q) => $q->whereNull('enrolments.left_on')->orWhereRaw("monthly_hours.month <= date_trunc('month', enrolments.left_on)::date"))
            ->when($first, fn ($q, $m) => $q->where('monthly_hours.month', $m->toDateString()))
            ->groupBy('monthly_hours.enrolment_id', 'monthly_hours.session_module_id')
            ->selectRaw('monthly_hours.enrolment_id as enrolment_id, monthly_hours.session_module_id as module_id, sum(monthly_hours.minutes)::bigint as minutes')
            ->toBase()->get();

        $realised = [];
        foreach ([...$attendance->all(), ...$monthly->all()] as $row) {
            $key = $row->enrolment_id.'|'.$row->module_id;
            $realised[$key] = ($realised[$key] ?? 0) + (int) $row->minutes;
        }

        $closed = AttendanceClosure::query()->whereIn('training_session_id', $sessionIds)->whereNotNull('closed_at')
            ->when($first, fn ($q, $m) => $q->where('month', $m->toDateString()))
            ->orderBy('month')->get(['training_session_id', 'month'])
            ->groupBy('training_session_id')
            ->map(fn ($rows) => $rows->map(fn (AttendanceClosure $c) => Months::key($c->month))->values()->all());

        $cuts = IndividualPlanning::moduleCuts($enrolments);
        $result = [];
        foreach ($enrolments as $enrolment) {
            $byModule = [];
            $planned = 0;
            $total = 0;
            foreach ($modules[$enrolment->training_session_id] ?? [] as $module) {
                $modulePlanned = $module->planned_minutes ?? 0;
                $moduleRealised = $realised[$enrolment->id.'|'.$module->id] ?? 0;
                // His part of the module: his individual planning may take some of it off.
                $his = max(0, $modulePlanned - ($cuts[$enrolment->id]['modules'][$module->id] ?? 0));
                $byModule[] = ['module_id' => $module->id, 'planned' => $his, 'realised' => $moduleRealised];
                $planned += $modulePlanned;
                $total += $moduleRealised;
            }
            // Séances added from other sessions (catch-ups): one « rattrapage » entry, module null.
            $own = array_map(fn (array $m) => $m['module_id'], $byModule);
            $catchUp = 0;
            foreach ($realised as $key => $minutes) {
                [$enrolmentId, $moduleId] = explode('|', $key);
                if ($enrolmentId === $enrolment->id && ! in_array($moduleId, $own, true)) {
                    $catchUp += $minutes;
                }
            }
            $addedPlanned = $cuts[$enrolment->id]['added'] ?? 0;
            if ($catchUp > 0 || $addedPlanned > 0) {
                $byModule[] = ['module_id' => null, 'planned' => $addedPlanned, 'realised' => $catchUp];
                $total += $catchUp;
            }
            $result[$enrolment->id] = [
                'planned_minutes' => $planned,
                'enrolment_planned_minutes' => SessionModules::minutesOf($enrolment->planned_hours) ?? $planned,
                'realised_minutes' => $total,
                'by_module' => $byModule,
                'closed_months' => $closed[$enrolment->training_session_id] ?? [],
            ];
        }

        return $result;
    }

    private static function month(?string $month): ?CarbonImmutable
    {
        if ($month === null) {
            return null;
        }

        return Months::parse($month) ?? throw new InvalidArgumentException("Mois invalide : {$month}");
    }
}
