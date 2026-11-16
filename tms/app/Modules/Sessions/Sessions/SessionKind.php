<?php

namespace App\Modules\Sessions\Sessions;

/**
 * Intra: for one entreprise (only its dossiers enrol), no number of places.
 * Inter: open to several dossiers, with a number of places (waiting list beyond).
 */
enum SessionKind: string
{
    case Intra = 'intra';
    case Inter = 'inter';

    public function label(): string
    {
        return match ($this) {
            self::Intra => 'Intra',
            self::Inter => 'Inter',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $k) => ['value' => $k->value, 'label' => $k->label()], self::cases());
    }
}
