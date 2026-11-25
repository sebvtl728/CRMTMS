<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
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
