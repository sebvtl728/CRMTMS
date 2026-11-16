<?php

namespace App\Modules\Sessions;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Amounts of the Sessions module, exact to the cent in integers only
 * (brick/math, never a float), with the rounding of the quotes:
 * line HT = round_half_up(quantity × unit price × (1 − discount / 100)).
 */
final class Amounts
{
    /** Largest amount of a numeric(12,2) column, in cents. */
    public const MAX_CENTS = 999_999_999_999;

    /**
     * Total HT of a line in cents, from validated decimals (two decimals at
     * most): round_half_up(q × p × (10000 − d) / 1 000 000) in hundredths
     * of quantity, cents and hundredths of percent.
     */
    public static function lineTotal(string $quantity, string $unitPrice, string $discountPercent): int
    {
        return self::hundredths($quantity)
            ->multipliedBy(self::hundredths($unitPrice))
            ->multipliedBy(BigInteger::of(10000)->minus(self::hundredths($discountPercent)))
            ->dividedBy(1_000_000, RoundingMode::HalfUp)
            ->toInt();
    }

    /**
     * Σ of amounts stored as numeric(12,2) strings, in cents.
     *
     * @param  iterable<string|null>  $decimals
     */
    public static function sum(iterable $decimals): int
    {
        $total = BigInteger::zero();
        foreach ($decimals as $decimal) {
            if ($decimal !== null) {
                $total = $total->plus(self::hundredths($decimal));
            }
        }

        return $total->toInt();
    }

    /** « 12.5 » → 1250. */
    public static function cents(string $decimal): int
    {
        return self::hundredths($decimal)->toInt();
    }

    /** 125050 → « 1250.50 » (stored numeric(12,2)). */
    public static function decimal(int $cents): string
    {
        return (string) BigDecimal::ofUnscaledValue($cents, 2);
    }

    /** 1250050 → « 12 500,50 € » (narrow spaces avoided: a plain space). */
    public static function display(int $cents): string
    {
        return self::french($cents).' €';
    }

    /** 1250050 → « 12 500,50 » (CSV, list cells). */
    public static function french(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.number_format(intdiv($cents, 100), 0, ',', ' ').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A decimal typed in French or not (« 1 234,5 », « 1234.50 ») as « 1234.5 »;
     * null when it is not a positive number with two decimals at most.
     */
    public static function normalise(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($value));

        return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) === 1 ? $value : null;
    }

    private static function hundredths(string $decimal): BigInteger
    {
        return BigDecimal::of($decimal)->toScale(2)->getUnscaledValue();
    }
}
