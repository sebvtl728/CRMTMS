<?php

namespace App\Modules\Sessions\Sessions;

/**
 * Where a session takes place: a free address (required), at the
 * customer's (an optional detail) or remotely (an optional link or detail).
 */
enum SessionLocation: string
{
    case Address = 'address';
    case Client = 'client';
    case Remote = 'remote';

    public function label(): string
    {
        return match ($this) {
            self::Address => 'Adresse',
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
