<?php

namespace App\Modules\Sessions\Sessions;

use Carbon\CarbonImmutable;

/**
 * Life of a training session: fixed in the code (it drives rules), not a
 * référentiel. « En cours » and « Terminée » are read from the dates while
 * the stored state is « Planifiée » or « Confirmée » (effective()); a
 * cancellation goes through SessionWriter::cancel() with a reason.
 */
enum SessionStatus: string
{
    case Planned = 'planned';
    case Confirmed = 'confirmed';
    case Running = 'running';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /** States the form offers (the others are computed or need a reason). */
    public const SETTABLE = [self::Planned, self::Confirmed];

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planifiée',
            self::Confirmed => 'Confirmée',
            self::Running => 'En cours',
            self::Done => 'Terminée',
            self::Cancelled => 'Annulée',
        };
    }

    /**
     * The state shown on $today (a Paris day): a planned or confirmed session
     * is running between its dates (both included) and done after its end,
     * or from its last day once its last month is closed ($lastMonthClosed).
     */
    public function effective(CarbonImmutable $startsOn, CarbonImmutable $endsOn, CarbonImmutable $today, bool $lastMonthClosed = false): self
    {
        if (! in_array($this, self::SETTABLE, true)) {
            return $this;
        }
        $day = $today->toDateString();

        return match (true) {
            $day > $endsOn->toDateString(), $lastMonthClosed && $day === $endsOn->toDateString() => self::Done,
            $day >= $startsOn->toDateString() => self::Running,
            default => $this,
        };
    }

    /**
     * @return array{value: string, label: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => $s->toOption(), self::cases());
    }
}
