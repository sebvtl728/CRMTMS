<?php

namespace App\Modules\Sessions\Planning;

use Carbon\CarbonImmutable;

/**
 * The 11 French national public holidays (Whit Monday included), computed
 * locally, without network: fixed days and those following Easter
 * (Gregorian computus of Meeus / Jones / Butcher). Alsace-Moselle and the
 * overseas departments are out of 0.8.0.
 */
final class PublicHolidays
{
    /** @var array<int, array<string, string>> */
    private static array $years = [];

    /**
     * The holidays of $year, by day: « Y-m-d » => French label, in order.
     *
     * @return array<string, string>
     */
    public static function for(int $year): array
    {
        if (isset(self::$years[$year])) {
            return self::$years[$year];
        }
        $easter = self::easter($year);
        $days = [
            "{$year}-01-01" => 'Jour de l\'an',
            $easter->addDay()->toDateString() => 'Lundi de Pâques',
            "{$year}-05-01" => 'Fête du Travail',
            "{$year}-05-08" => 'Victoire 1945',
            $easter->addDays(39)->toDateString() => 'Ascension',
            $easter->addDays(50)->toDateString() => 'Lundi de Pentecôte',
            "{$year}-07-14" => 'Fête nationale',
            "{$year}-08-15" => 'Assomption',
            "{$year}-11-01" => 'Toussaint',
            "{$year}-11-11" => 'Armistice 1918',
            "{$year}-12-25" => 'Noël',
        ];
        ksort($days);

        return self::$years[$year] = $days;
    }

    /**
     * The label of the holiday on day $date (« Y-m-d »), null on a working day.
     */
    public static function label(string $date): ?string
    {
        return self::for((int) substr($date, 0, 4))[$date] ?? null;
    }

    /**
     * The holidays from $from to $to (« Y-m-d », bounds included), in order.
     *
     * @return array<string, string>
     */
    public static function between(string $from, string $to): array
    {
        $days = [];
        for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
            foreach (self::for($year) as $day => $label) {
                if ($day >= $from && $day <= $to) {
                    $days[$day] = $label;
                }
            }
        }

        return $days;
    }

    /**
     * Easter Sunday of $year (anonymous Gregorian algorithm).
     */
    private static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC') ?? throw new \LogicException('Easter');
    }
}
