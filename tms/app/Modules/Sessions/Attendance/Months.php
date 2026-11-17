<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Paris months of the attendance, written « 2026-09 » and stored as their
 * 1st day.
 */
final class Months
{
    public const INVALID = 'Mois invalide.';

    /** « 2026-09 » → 2026-09-01 (Paris), null when malformed. */
    public static function parse(mixed $month): ?CarbonImmutable
    {
        if (! is_string($month) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', "{$month}-01", 'Europe/Paris') ?: null;
    }

    /**
     * @throws ValidationException
     */
    public static function required(mixed $month, string $field = 'month'): CarbonImmutable
    {
        return self::parse($month) ?? throw ValidationException::withMessages([$field => self::INVALID]);
    }

    /** The 1st of the Paris month of $date. */
    public static function of(CarbonImmutable|string $date): CarbonImmutable
    {
        $day = is_string($date) ? CarbonImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), 'Europe/Paris') : $date;
        if ($day === null) {
            throw new \InvalidArgumentException("Date invalide : {$date}");
        }

        return $day->setTimezone('Europe/Paris')->startOfMonth();
    }

    /** « septembre 2026 » */
    public static function label(CarbonImmutable|string $month): string
    {
        $first = is_string($month) ? (self::parse($month) ?? self::of($month)) : $month;

        return $first->locale('fr')->isoFormat('MMMM YYYY');
    }

    /** « 2026-09 » */
    public static function key(CarbonImmutable $date): string
    {
        return $date->format('Y-m');
    }

    /**
     * The months from $from to $to (both included), as « 2026-09 ».
     *
     * @return list<string>
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        for ($m = self::of($from); $m->lte(self::of($to)); $m = $m->addMonthNoOverflow()) {
            $months[] = self::key($m);
        }

        return $months;
    }

    /**
     * @return list<string>
     */
    public static function ofSession(TrainingSession $session): array
    {
        return self::between($session->starts_on, $session->ends_on);
    }
}
