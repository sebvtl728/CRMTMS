<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Planning\IndividualPlanning;
use App\Modules\Sessions\Planning\Seance;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a half-day covers: the live (not cancelled, not deleted) séances of
 * the session that day overlapping it (Halves::of), and the trainees
 * expected at one of them (Attendances::expected, same entry / leaving rules)
 * whose own hours touch the half-day (individual planning).
 */
final class SlotSeances
{
    /**
     * @return Collection<int, Seance>
     */
    public static function of(string $sessionId, string $date, string $half): Collection
    {
        return Seance::query()->where('training_session_id', $sessionId)->where('date', $date)
            ->where('status', Seance::PLANNED)->orderBy('starts_at')->orderBy('id')->get()
            ->filter(fn (Seance $s) => in_array($half, Halves::of($s->start(), $s->end()), true))->values();
    }

    /**
     * @return Collection<int, Seance>
     */
    public static function ofSlot(SignSlot $slot): Collection
    {
        return self::of($slot->training_session_id, $slot->date->toDateString(), $slot->half);
    }

    /**
     * @return Collection<int, Enrolment>
     */
    public static function expected(SignSlot $slot): Collection
    {
        $out = new Collection;
        foreach (self::ofSlot($slot) as $seance) {
            $enrolments = Attendances::expected($seance)->orderBy('id')->get();
            $times = IndividualPlanning::times($seance, $enrolments->pluck('id')->all());
            foreach ($enrolments as $enrolment) {
                // His own hours may leave this half-day out (individual planning).
                if (in_array($slot->half, Halves::of($times[$enrolment->id]['start'], $times[$enrolment->id]['end']), true)) {
                    $out->put($enrolment->id, $enrolment);
                }
            }
        }

        return $out->sortKeys()->values();
    }

    /**
     * The séances of the half-day each trainee follows, at his hours: one
     * entry per (séance, trainee) expected at the séance whose hours touch
     * the half-day.
     *
     * @return list<array{seance: Seance, enrolment_id: string, start: string, end: string, minutes: int}>
     */
    public static function followed(SignSlot $slot): array
    {
        $out = [];
        foreach (self::ofSlot($slot) as $seance) {
            $ids = Attendances::expected($seance)->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
            foreach (IndividualPlanning::times($seance, $ids) as $id => $t) {
                $out[] = ['seance' => $seance, 'enrolment_id' => $id, ...$t];
            }
        }

        return $out;
    }
}
