<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Planning\Seance;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a half-day covers: the live (not cancelled, not deleted) séances of
 * the session that day overlapping it (Halves::of), and the trainees
 * expected at one of them (Attendances::expected, same entry / leaving rules).
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
        $seance = self::ofSlot($slot)->first();

        // Every séance of a session on a day expects the same trainees (the rule reads the day only).
        return $seance === null ? new Collection : Attendances::expected($seance)->orderBy('id')->get();
    }
}
