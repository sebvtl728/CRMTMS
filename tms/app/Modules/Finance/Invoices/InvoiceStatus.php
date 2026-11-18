<?php

namespace App\Modules\Finance\Invoices;

/**
 * Life of an invoice or a credit note (spec 0.9 § 4.4): a draft (no
 * number, editable, deletable), validated (definitive number, frozen),
 * sent by e-mail.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Sent = 'sent';

    /** Validated or sent: counts as invoiced, never changes again. */
    public const ISSUED = [self::Validated, self::Sent];

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Validated => 'Validée',
            self::Sent => 'Envoyée',
        };
    }

    /**
     * @param  list<self>  $cases
     * @return list<string>
     */
    public static function values(array $cases): array
    {
        return array_map(fn (self $s) => $s->value, $cases);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
