<?php

namespace App\Modules\Core\Exports;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A typed cell of an export: written as a number or a date (with its
 * French display format) rather than text, so that Excel can sum, sort and
 * filter it. Text stays a plain string (see XlsxExporter::neutralise()).
 */
final class ExportCell
{
    /** « 1 234,50 € » in a French Excel. */
    public const AMOUNT_FORMAT = '#,##0.00\ "€"';

    public const INTEGER_FORMAT = '0';

    /** A decimal shown as is (« 14,5 », « 2 ») in a French Excel. */
    public const DECIMAL_FORMAT = 'General';

    /** « 20/12/2026 ». */
    public const DATE_FORMAT = 'dd/mm/yyyy';

    /**
     * A cell as text for the screen (list tables): « 1 234,50 », « 10 »,
     * « 20/12/2026 »; text as is.
     */
    public static function display(string|self|null $cell): ?string
    {
        if (! $cell instanceof self) {
            return $cell;
        }

        return match (true) {
            $cell->value instanceof DateTimeInterface => $cell->value->format('d/m/Y'),
            $cell->format === self::AMOUNT_FORMAT => number_format((float) $cell->value, 2, ',', ' '),
            $cell->format === self::DECIMAL_FORMAT => str_replace('.', ',', (string) $cell->value),
            default => (string) $cell->value,
        };
    }

    private function __construct(public readonly int|float|DateTimeInterface $value, public readonly string $format) {}

    /** A decimal amount (« 1234.50 » as stored), null when empty. */
    public static function amount(?string $value): ?self
    {
        return $value === null || $value === '' ? null : new self((float) $value, self::AMOUNT_FORMAT);
    }

    /** A decimal quantity (« 14.50 » as stored: hours, days), null when empty. */
    public static function decimal(?string $value): ?self
    {
        return $value === null || $value === '' ? null : new self((float) $value, self::DECIMAL_FORMAT);
    }

    public static function integer(?int $value): ?self
    {
        return $value === null ? null : new self($value, self::INTEGER_FORMAT);
    }

    /** The Paris calendar day of $at (a date or an instant), null when empty. */
    public static function date(?DateTimeInterface $at): ?self
    {
        if ($at === null) {
            return null;
        }
        $day = CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'))->format('Y-m-d');

        return new self(CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC') ?: CarbonImmutable::parse($day, 'UTC'), self::DATE_FORMAT);
    }
}
