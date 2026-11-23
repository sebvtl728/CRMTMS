<?php

namespace App\Modules\Sessions\Sessions;

/**
 * Where a session takes place: a free address (required), in the premises
 * of one of our companies (location_company_id, an optional detail such as
 * the room), at the customer's (an optional detail) or remotely (an
 * optional link or detail).
 */
enum SessionLocation: string
{
    case Address = 'address';
    case Company = 'company';
    case Client = 'client';
    case Remote = 'remote';

    public function label(): string
    {
        return match ($this) {
            self::Address => 'Adresse',
            self::Company => 'Dans nos locaux',
            self::Client => 'Chez le client',
            self::Remote => 'Distanciel',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $l) => ['value' => $l->value, 'label' => $l->label()], self::cases());
    }
}
