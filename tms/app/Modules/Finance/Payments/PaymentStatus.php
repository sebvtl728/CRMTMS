<?php

namespace App\Modules\Finance\Payments;

/**
 * Payment state of an issued invoice (spec 0.9.1 § 4.2), computed by
 * Balances, never stored: the invoice is frozen. « En retard » is a flag
 * beside it (not settled and past its due date).
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Non réglée',
            self::Partial => 'Partiellement réglée',
            self::Paid => 'Soldée',
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
