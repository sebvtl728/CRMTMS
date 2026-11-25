<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The individual planning of a trainee (spec 2026-11-24): the séances of
 * his session within his entry and leaving days, without the ones he is
 * exempted from (a séance, a whole module), plus the séances of other
 * sessions added to him, at his own hours where he has some. The one
 * source of « who is expected at which séance, for how long »: every reader
 * (attendance, signing, hours, documents, reminders, follow-up) goes
 * through it; no other class reads enrolment_adjustments.
 */
final class IndividualPlanning
{
    /**
     * The trainees expected at the séance, as a query on enrolments.
     *
     * @return Builder<Enrolment>
     */
    public static function expectedAt(Seance $seance): Builder
    {
        $date = $seance->date->toDateString();

        return Enrolment::query()->where('training_session_id', $seance->training_session_id)
            ->whereIn('status', Attendances::ATTENDING)
            ->where(fn (Builder $q) => $q->whereNull('entered_on')->orWhere('entered_on', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $date))
            ->whereNotExists(fn (QueryBuilder $q) => self::exemptionOf($q, $seance));
    }

    /** Whether the trainee is exempted from the séance (itself or its module). */
    public static function exempted(string $enrolmentId, Seance $seance): bool
    {
        return EnrolmentAdjustment::query()->where('enrolment_id', $enrolmentId)
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('kind', EnrolmentAdjustment::EXEMPT_SEANCE)->where('seance_id', $seance->id))
                ->orWhere(fn (Builder $q) => $q->where('kind', EnrolmentAdjustment::EXEMPT_MODULE)->where('session_module_id', $seance->session_module_id)))
            ->exists();
    }

    /**
     * The hours of each trainee at the séance: his own hours where he has
     * some, kept within the séance (a séance moved since: the overlap, the
     * séance's hours when nothing overlaps), else the séance's. One query.
     *
     * @param  list<string>  $enrolmentIds
     * @return array<string, array{start: string, end: string, break: int, minutes: int}>
     */
    public static function times(Seance $seance, array $enrolmentIds): array
    {
        $own = $enrolmentIds === [] ? collect() : EnrolmentAdjustment::query()->where('seance_id', $seance->id)
            ->whereIn('enrolment_id', $enrolmentIds)->whereNotNull('starts_at')
            ->get(['enrolment_id', 'starts_at', 'ends_at', 'break_minutes'])->keyBy('enrolment_id');
        $out = [];
        foreach ($enrolmentIds as $id) {
            $out[$id] = self::hoursOf($seance, $own[$id] ?? null);
        }

        return $out;
    }

    /** His duration at the séance, in minutes (see times()). */
    public static function minutesAt(string $enrolmentId, Seance $seance): int
    {
        return self::times($seance, [$enrolmentId])[$enrolmentId]['minutes'];
    }

    /**
     * @return array{start: string, end: string, break: int, minutes: int}
     */
    public static function hoursOf(Seance $seance, ?EnrolmentAdjustment $own): array
    {
        $default = ['start' => $seance->start(), 'end' => $seance->end(), 'break' => $seance->break_minutes, 'minutes' => $seance->duration_minutes];
        if ($own === null || $own->starts_at === null || $own->ends_at === null) {
            return $default;
        }
        $start = max(substr($own->starts_at, 0, 5), $seance->start());
        $end = min(substr($own->ends_at, 0, 5), $seance->end());
        if ($end <= $start) {
            return $default;
        }
        $span = self::minutesOfDay($end) - self::minutesOfDay($start);
        $break = min((int) $own->break_minutes, $span - 1);

        return ['start' => $start, 'end' => $end, 'break' => $break, 'minutes' => $span - $break];
    }

    /**
     * His planned minutes (spec § 6): the base (his planned hours before the
     * first adjustment) − per module of his session, the whole module when
     * exempted, else the séances exempted and the minutes his own hours
     * miss, capped at the module's planned minutes − + the séances added.
     * Cancelled and deleted séances count nothing; never below 0.
     */
    public static function plannedMinutes(Enrolment $enrolment): int
    {
        $session = TrainingSession::withTrashed()->findOrFail($enrolment->training_session_id);
        $base = SessionModules::minutesOf($enrolment->planned_hours_base ?? $enrolment->planned_hours ?? $session->planned_hours) ?? 0;
        $modules = SessionModule::query()->where('training_session_id', $session->id)->pluck('planned_minutes', 'id');
        $adjustments = EnrolmentAdjustment::query()->with('seance')->where('enrolment_id', $enrolment->id)->get();

        $exemptModules = $adjustments->where('kind', EnrolmentAdjustment::EXEMPT_MODULE)->pluck('session_module_id')->all();
        $less = 0;
        $byModule = [];
        $added = 0;
        foreach ($adjustments as $adjustment) {
            $seance = $adjustment->seance;
            if ($seance === null || $seance->trashed() || $seance->isCancelled()) {
                continue;
            }
            $hours = self::hoursOf($seance, $adjustment);
            match ($adjustment->kind) {
                EnrolmentAdjustment::ADDED_SEANCE => $added += $hours['minutes'],
                EnrolmentAdjustment::EXEMPT_SEANCE => $byModule[$seance->session_module_id] = ($byModule[$seance->session_module_id] ?? 0) + $seance->duration_minutes,
                EnrolmentAdjustment::OWN_HOURS => $byModule[$seance->session_module_id] = ($byModule[$seance->session_module_id] ?? 0) + $seance->duration_minutes - $hours['minutes'],
                default => null,
            };
        }
        foreach ($modules as $moduleId => $planned) {
            $less += in_array($moduleId, $exemptModules, true) ? (int) $planned : min($byModule[$moduleId] ?? 0, (int) $planned);
        }

        return max(0, $base - $less + $added);
    }

    /**
     * Writes his computed planned hours (in the caller's transaction): with
     * adjustments, the base is kept (planned_hours_base, set at the first
     * one) and planned_hours computed; without any, planned_hours goes back
     * to the base and the base is emptied.
     */
    public static function refresh(Enrolment $enrolment): void
    {
        $has = EnrolmentAdjustment::query()->where('enrolment_id', $enrolment->id)->exists();
        if (! $has) {
            if ($enrolment->planned_hours_base !== null) {
                $enrolment->planned_hours = $enrolment->planned_hours_base;
                $enrolment->planned_hours_base = null;
                $enrolment->save();
            }

            return;
        }
        if ($enrolment->planned_hours_base === null) {
            $enrolment->planned_hours_base = $enrolment->planned_hours
                ?? TrainingSession::withTrashed()->findOrFail($enrolment->training_session_id)->planned_hours;
        }
        $enrolment->planned_hours = SessionModules::hoursDecimal(self::plannedMinutes($enrolment));
        if ($enrolment->isDirty()) {
            $enrolment->save();
        }
    }

    /**
     * A séance was moved, cancelled or deleted: the planned hours of the
     * trainees with an adjustment on it are computed again (id order).
     */
    public static function refreshForSeance(Seance $seance): void
    {
        $ids = EnrolmentAdjustment::query()->where('seance_id', $seance->id)->distinct()->orderBy('enrolment_id')->pluck('enrolment_id')->all();
        foreach (Enrolment::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $enrolment) {
            self::refresh($enrolment);
        }
    }

    private static function minutesOfDay(string $time): int
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }

    private static function exemptionOf(QueryBuilder $q, Seance $seance): void
    {
        $q->selectRaw('1')->from('enrolment_adjustments')
            ->whereColumn('enrolment_adjustments.enrolment_id', 'enrolments.id')
            ->where(fn (QueryBuilder $q) => $q->where(fn (QueryBuilder $q) => $q->where('kind', EnrolmentAdjustment::EXEMPT_SEANCE)->where('seance_id', $seance->id))
                ->orWhere(fn (QueryBuilder $q) => $q->where('kind', EnrolmentAdjustment::EXEMPT_MODULE)->where('session_module_id', $seance->session_module_id)));
    }
}
