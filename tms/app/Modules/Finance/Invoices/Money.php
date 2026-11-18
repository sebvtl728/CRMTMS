<?php

namespace App\Modules\Finance\Invoices;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Integer cents to French text and back, never through a float.
 */
final class Money
{
    private const NBSP = "\u{00A0}";

    /** -171430 → « -1 714,30 € » (U+00A0: never wraps). */
    public static function format(int $cents): string
    {
        return self::input($cents, grouped: true).self::NBSP.'€';
    }

    /** 171430 → « 1714,30 » (as typed in a form); grouped: « 1 714,30 ». */
    public static function input(int $cents, bool $grouped = false): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        $units = (string) intdiv($abs, 100);
        if ($grouped) {
            $units = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', self::NBSP, $units);
        }

        return $sign.$units.','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * « 1 200,50 », « -12 », « 1200.5 » → cents; null when it is not an
     * amount (at most 2 decimals, 10 digits).
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }
        if (! is_string($value)) {
            return null;
        }
        $text = str_replace([' ', self::NBSP, "\u{202F}", '€'], '', trim($value));
        $text = str_replace(',', '.', $text);
        if (preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $text) !== 1) {
            return null;
        }

        return BigDecimal::of($text)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
