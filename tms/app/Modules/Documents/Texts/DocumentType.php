<?php

namespace App\Modules\Documents\Texts;

/**
 * The training documents the TMS issues. Delivery 1: the convention (a
 * company contractor) and the contract (a particulier).
 */
enum DocumentType: string
{
    case Convention = 'convention';
    case Contract = 'contract';

    public function label(): string
    {
        return match ($this) {
            self::Convention => 'Convention de formation',
            self::Contract => 'Contrat de formation',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
