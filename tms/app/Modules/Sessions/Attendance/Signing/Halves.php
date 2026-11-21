<?php

namespace App\Modules\Sessions\Attendance\Signing;

/**
 * The half-days of the online signing, pure (no database, no clock):
 * morning = a séance starting before 13:00, afternoon = one ending after
 * 13:00 (the cut of the printed sheet, AttendanceSheetPdf). The minutes of
 * the signed half-days are the séance's duration prorated on the clock
 * minutes of each half (the break is spread evenly).
 */
final class Halves
{
    public const CUT = '13:00';

    /**
     * @return list<string> morning and/or afternoon, in that order
     */
    public static function of(string $start, string $end): array
    {
        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);
        $halves = [];
        if ($start < self::CUT) {
            $halves[] = SignSlot::MORNING;
        }
        if ($end > self::CUT) {
            $halves[] = SignSlot::AFTERNOON;
        }

        return $halves;
    }

    /**
     * @param  list<string>  $signed  half-days signed
     */
    public static function minutes(int $duration, string $start, string $end, array $signed): int
    {
        $from = self::clock($start);
        $to = self::clock($end);
        $cut = self::clock(self::CUT);
        $span = $to - $from;
        if ($span <= 0 || $duration <= 0) {
            return 0;
        }
        $overlap = 0;
        if (in_array(SignSlot::MORNING, $signed, true)) {
            $overlap += max(0, min($to, $cut) - $from);
        }
        if (in_array(SignSlot::AFTERNOON, $signed, true)) {
            $overlap += max(0, $to - max($from, $cut));
        }

        return max(0, min($duration, (int) round($duration * $overlap / $span)));
    }

    private static function clock(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $h * 60 + $m;
    }
}
