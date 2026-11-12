<?php

namespace App\Modules\Crm\Organisations;

enum OrganisationStatus: string
{
    case Prospect = 'prospect';
    case Client = 'client';
    case Inactif = 'inactif';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Client => 'Client',
            self::Inactif => 'Inactif',
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
