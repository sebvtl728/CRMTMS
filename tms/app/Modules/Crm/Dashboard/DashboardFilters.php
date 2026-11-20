<?php

namespace App\Modules\Crm\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Filters of the « Tableaux de bord » page (lot 1e): a period in days of
 * Paris (a preset or free dates; the previous period is the same kind of
 * period just before, or as long and just before for free dates), a DAS, a
 * target (organisation | person) and a responsible user.
 */
final readonly class DashboardFilters
{
    public const PRESETS = ['this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'this_year', 'custom'];

    public const TARGETS = ['organisation', 'person'];

    private const TZ = 'Europe/Paris';

    private function __construct(
        public string $tab,
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?string $das,
        public ?string $target,
        public ?string $responsible,
    ) {}

    /** @param  array<array-key, mixed>  $input */
    public static function from(array $input, CarbonImmutable $now, string $tab): self
    {
        $tab = $tab === Indicator::MONTH ? Indicator::MONTH : Indicator::WEEK;
        $default = $tab === Indicator::MONTH ? 'this_month' : 'this_week';
        $preset = in_array($input['preset'] ?? null, self::PRESETS, true) ? (string) $input['preset'] : $default;
        $today = $now->setTimezone(self::TZ)->startOfDay();
        $range = $preset === 'custom' ? self::custom($input) : null;
        if ($preset === 'custom' && $range === null) {
            $preset = $default;
        }
        [$from, $to] = $range ?? self::range($preset, $today);
        $uuid = fn (mixed $v): ?string => is_string($v) && Str::isUuid($v) ? $v : null;

        return new self($tab, $preset, $from, $to, $uuid($input['das'] ?? null),
            in_array($input['target'] ?? null, self::TARGETS, true) ? (string) $input['target'] : null, $uuid($input['responsible'] ?? null));
    }

    /** The same kind of period just before (a free period: as long, just before). */
    public function previous(): self
    {
        [$from, $to] = match ($this->preset) {
            'this_week', 'last_week' => [$this->from->subWeek(), $this->to->subWeek()],
            'this_month', 'last_month' => [$this->from->subMonthNoOverflow()->startOfMonth(), $this->from->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [$this->from->subMonthsNoOverflow(3)->startOfMonth(), $this->from->subDay()],
            'this_year' => [$this->from->subYear(), $this->to->subYear()],
            default => [$this->from->subDays($this->days()), $this->from->subDay()],
        };

        return new self($this->tab, $this->preset, $from, $to, $this->das, $this->target, $this->responsible);
    }

    /** Number of days of the period. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** First instant of the period (Paris). */
    public function start(): CarbonImmutable
    {
        return $this->from->startOfDay();
    }

    /** Last instant of the period (Paris). */
    public function end(): CarbonImmutable
    {
        return $this->to->endOfDay();
    }

    /** « du 16/11/2026 au 22/11/2026 ». */
    public function label(): string
    {
        return 'du '.$this->from->format('d/m/Y').' au '.$this->to->format('d/m/Y');
    }

    /** Stable key of the filters (cache). */
    public function key(): string
    {
        return sha1(implode('|', [$this->tab, $this->from->toDateString(), $this->to->toDateString(), $this->das, $this->target, $this->responsible]));
    }

    /** @return array{tab: string, preset: string, from: string, to: string, das: ?string, target: ?string, responsible: ?string} */
    public function toArray(): array
    {
        return ['tab' => $this->tab, 'preset' => $this->preset, 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(),
            'das' => $this->das, 'target' => $this->target, 'responsible' => $this->responsible];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function range(string $preset, CarbonImmutable $today): array
    {
        return match ($preset) {
            'last_week' => [$today->subWeek()->startOfWeek(CarbonImmutable::MONDAY), $today->subWeek()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [$today->startOfQuarter(), $today->endOfQuarter()->startOfDay()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()->startOfDay()],
            default => [$today->startOfWeek(CarbonImmutable::MONDAY), $today->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
        };
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private static function custom(array $input): ?array
    {
        $date = function (mixed $v): ?CarbonImmutable {
            if (! is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
                return null;
            }
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $v, self::TZ);

            return $d !== null && $d->toDateString() === $v ? $d : null;
        };
        $from = $date($input['from'] ?? null);
        $to = $date($input['to'] ?? null);

        return $from === null || $to === null || $from->greaterThan($to) || $from->diffInDays($to) > 3660 ? null : [$from, $to];
    }
}
