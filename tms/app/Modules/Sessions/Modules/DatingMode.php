<?php

namespace App\Modules\Sessions\Modules;

/**
 * How a session module is dated: an interval (start, end, hours; no séance)
 * or dated séances.
 */
enum DatingMode: string
{
    case Interval = 'interval';
    case Seances = 'seances';

    public function label(): string
    {
        return match ($this) {
            self::Interval => 'Intervalle de dates',
            self::Seances => 'Séances',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::cases());
    }
}
