<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Sessions\Resources\ClosurePeriods;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The period a planning page shows: a month (its whole weeks, Monday to
 * Sunday) or a week, around a Paris day (?view=month|week&date=YYYY-MM-DD;
 * today by default, anything malformed ignored). Days are calendar days.
 */
final readonly class PlanningPeriod
{
    public const MONTHS = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    /**
     * @param  'month'|'week'  $view
     */
    private function __construct(
        public string $view,
        public CarbonImmutable $date,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $view = $request->query('view') === 'week' ? 'week' : 'month';
        $raw = $request->query('date');
        $date = is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && checkdate((int) substr($raw, 5, 2), (int) substr($raw, 8, 2), (int) substr($raw, 0, 4))
            ? WorkingDays::day($raw)
            : WorkingDays::day(TrainingSession::today()->toDateString());

        return self::of($view, $date);
    }

    /**
     * @param  'month'|'week'  $view
     */
    public static function of(string $view, CarbonImmutable $date): self
    {
        [$from, $to] = $view === 'week'
            ? [$date->startOfWeek(CarbonImmutable::MONDAY), $date->endOfWeek(CarbonImmutable::SUNDAY)]
            : [$date->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY), $date->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)];

        return new self($view, $date, $from->startOfDay(), $to->startOfDay());
    }

    /**
     * @return array{view: string, date: string, month: string, from: string, to: string, label: string, prev: string, next: string, today: string}
     */
    public function toArray(): array
    {
        $week = $this->view === 'week';

        return [
            'view' => $this->view,
            'date' => $this->date->toDateString(),
            'month' => $this->date->format('Y-m'),
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'label' => $week
                ? 'Semaine du '.$this->from->format('d/m/Y').' au '.$this->to->format('d/m/Y')
                : self::MONTHS[$this->date->month].' '.$this->date->year,
            'prev' => ($week ? $this->date->subWeek() : $this->date->startOfMonth()->subMonth())->toDateString(),
            'next' => ($week ? $this->date->addWeek() : $this->date->startOfMonth()->addMonth())->toDateString(),
            'today' => TrainingSession::today()->toDateString(),
        ];
    }

    /**
     * Holidays and closures of the period, for the shading of the grid.
     *
     * @return array{holidays: array<string, string>, closures: list<array{starts_on: string, ends_on: string, label: string}>}
     */
    public function shading(): array
    {
        return [
            'holidays' => PublicHolidays::between($this->from->toDateString(), $this->to->toDateString()),
            'closures' => SeanceRows::closures(ClosurePeriods::between($this->from, $this->to)),
        ];
    }
}
