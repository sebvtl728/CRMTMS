<?php

namespace App\Modules\Crm\Gdpr;

enum ConsentChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Phone = 'phone';
    case Prospecting = 'prospecting';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::Sms => 'SMS',
            self::Phone => 'Téléphone',
            self::Prospecting => 'Prospection',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
