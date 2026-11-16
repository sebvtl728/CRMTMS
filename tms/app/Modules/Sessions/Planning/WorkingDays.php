<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Resources\ClosurePeriods;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Working days of the organisme: Monday to Friday, without the public
 * holidays (PublicHolidays) nor its closure periods (ClosurePeriods). Days
 * are Paris calendar days (« Y-m-d »), never instants.
 */
final class WorkingDays
{
    /**
     * The working days from $from to $to (bounds included), in order.
     *
     * @param  Collection<int, ClosurePeriod>|null  $closures  those overlapping the range (loaded when null)
     * @return list<string>
     */
    public static function between(string $from, string $to, ?Collection $closures = null): array
    {
        if ($to < $from) {
            return [];
        }
        $closures ??= ClosurePeriods::between(self::day($from), self::day($to));
        $holidays = PublicHolidays::between($from, $to);
        $closed = self::closedDays($closures, $from, $to);
        $days = [];
        for ($day = self::day($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            if ($day->isoWeekday() <= 5 && ! isset($holidays[$date]) && ! isset($closed[$date])) {
                $days[] = $date;
            }
        }

        return $days;
    }

    /**
     * Days covered by the closures within [$from, $to]: « Y-m-d » => label.
     *
     * @param  Collection<int, ClosurePeriod>  $closures
     * @return array<string, string>
     */
    public static function closedDays(Collection $closures, string $from, string $to): array
    {
        $closed = [];
        foreach ($closures as $closure) {
            $start = max($from, $closure->starts_on->toDateString());
            $end = min($to, $closure->ends_on->toDateString());
            for ($day = self::day($start); $day->toDateString() <= $end; $day = $day->addDay()) {
                $closed[$day->toDateString()] ??= $closure->label;
            }
        }

        return $closed;
    }

    /** A calendar day (midnight UTC: day arithmetic never meets a change of hour). */
    public static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC') ?: throw new \InvalidArgumentException("Jour invalide : {$date}");
    }
}
