<?php

namespace App\Modules\Core\Companies;

enum VatRegime: string
{
    case Exempt = 'exempt';
    case Subject = 'subject';

    public function label(): string
    {
        return match ($this) {
            self::Exempt => 'Exonéré de TVA',
            self::Subject => 'Assujetti à la TVA',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
