<?php

namespace App\Modules\Sessions\Trainers;

enum TrainerStatus: string
{
    case Employee = 'employee';
    case Freelance = 'freelance';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Salarié',
            self::Freelance => 'Indépendant',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
