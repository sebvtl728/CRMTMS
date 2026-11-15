<?php

namespace App\Modules\Crm\Revenue;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A month, a quarter or a year of Paris days (« 2026-11 », « 2026-T4 »,
 * « 2026 »). start and end are both included, at midnight of that day.
 */
final readonly class Period
{
    public const TYPES = ['month', 'quarter', 'year'];

    private const TIMEZONE = 'Europe/Paris';

    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private function __construct(
        public string $type,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /**
     * An invalid type or key is a 422 with a French message.
     */
    public static function of(string $type, string $key): self
    {
        $period = match ($type) {
            'month' => preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $key, $m) === 1 ? self::month((int) $m[1], (int) $m[2]) : null,
            'quarter' => preg_match('/^(\d{4})-T([1-4])$/', $key, $m) === 1 ? self::quarter((int) $m[1], (int) $m[2]) : null,
            'year' => preg_match('/^\d{4}$/', $key) === 1 ? self::year((int) $key) : null,
            default => null,
        };

        return $period ?? throw ValidationException::withMessages(['period' => 'La période demandée est invalide.']);
    }

    /** The period of $type holding $now (as a Paris day). */
    public static function current(string $type, CarbonImmutable $now): self
    {
        $day = $now->setTimezone(self::TIMEZONE);

        return match ($type) {
            'month' => self::month($day->year, $day->month),
            'quarter' => self::quarter($day->year, intdiv($day->month - 1, 3) + 1),
            'year' => self::year($day->year),
            default => throw ValidationException::withMessages(['period' => 'La période demandée est invalide.']),
        };
    }

    public function key(): string
    {
        return match ($this->type) {
            'month' => $this->start->format('Y-m'),
            'quarter' => $this->start->year.'-T'.(intdiv($this->start->month - 1, 3) + 1),
            default => (string) $this->start->year,
        };
    }

    public function label(): string
    {
        return match ($this->type) {
            'month' => self::MONTHS[$this->start->month - 1].' '.$this->start->year,
            'quarter' => (($n = intdiv($this->start->month - 1, 3) + 1) === 1 ? '1er' : $n.'e').' trimestre '.$this->start->year,
            default => (string) $this->start->year,
        };
    }

    public function previous(): self
    {
        if ($this->type === 'year') {
            return self::year($this->start->year - 1);
        }

        $before = $this->start->subMonths($this->type === 'month' ? 1 : 3);

        return $this->type === 'month'
            ? self::month($before->year, $before->month)
            : self::quarter($before->year, intdiv($before->month - 1, 3) + 1);
    }

    public function next(): self
    {
        if ($this->type === 'year') {
            return self::year($this->start->year + 1);
        }

        $after = $this->start->addMonths($this->type === 'month' ? 1 : 3);

        return $this->type === 'month'
            ? self::month($after->year, $after->month)
            : self::quarter($after->year, intdiv($after->month - 1, 3) + 1);
    }

    private static function month(int $year, int $month): self
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, self::TIMEZONE);

        return new self('month', $start, $start->endOfMonth()->startOfDay());
    }

    private static function quarter(int $year, int $quarter): self
    {
        $start = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1, 0, 0, 0, self::TIMEZONE);

        return new self('quarter', $start, $start->addMonths(2)->endOfMonth()->startOfDay());
    }

    private static function year(int $year): self
    {
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, self::TIMEZONE);

        return new self('year', $start, CarbonImmutable::create($year, 12, 31, 0, 0, 0, self::TIMEZONE));
    }
}
