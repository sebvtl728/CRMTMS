<?php

namespace App\Modules\Crm\Quotes;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Totals of a quote, exact to the cent, in integers only (brick/math):
 *
 * - line HT = round_half_up(quantity × unit price × (1 − discount / 100)),
 *   i.e. in hundredths of quantity, cents and hundredths of percent:
 *   round_half_up(q × p × (10000 − d) / 1 000 000) cents;
 * - VAT per rate = round_half_up(Σ HT of the lines of that rate × rate / 100)
 *   (not the sum of rounded line VATs);
 * - HT = Σ line HT, VAT = Σ VAT per rate, TTC = HT + VAT.
 *
 * Screen, PDF and export all read these amounts: never recompute them with
 * floats elsewhere.
 */
final class QuoteTotals
{
    /** Rates a company subject to VAT may use, highest first (« 20.00 »). */
    public const VAT_RATES = ['20.00', '10.00', '5.50', '2.10', '0.00'];

    public const DEFAULT_VAT_RATE = '20.00';

    /** Largest amount of a numeric(12,2) column, in cents. */
    public const MAX_CENTS = 999_999_999_999;

    /**
     * @param  list<LineInput>  $lines
     * @param  bool  $vatExempt  the company is exempt: every line at 0 %
     */
    public static function compute(array $lines, bool $vatExempt): Totals
    {
        $lineTotals = [];
        /** @var array<string, BigInteger> $bases */
        $bases = [];
        $ht = BigInteger::zero();

        foreach ($lines as $line) {
            $total = self::hundredths($line->quantity)
                ->multipliedBy(self::hundredths($line->unitPrice))
                ->multipliedBy(BigInteger::of(10000)->minus(self::hundredths($line->discountPercent)))
                ->dividedBy(1_000_000, RoundingMode::HalfUp);

            $rate = $vatExempt ? '0.00' : self::rate($line->vatRate);
            $bases[$rate] = ($bases[$rate] ?? BigInteger::zero())->plus($total);
            $lineTotals[] = $total->toInt();
            $ht = $ht->plus($total);
        }

        uksort($bases, fn (string $a, string $b) => BigDecimal::of($b)->compareTo(BigDecimal::of($a)));

        $vatByRate = [];
        $vat = BigInteger::zero();
        foreach ($bases as $rate => $base) {
            $amount = $base->multipliedBy(self::hundredths($rate))->dividedBy(10000, RoundingMode::HalfUp);
            $vatByRate[] = ['rate' => (string) $rate, 'base' => $base->toInt(), 'vat' => $amount->toInt()];
            $vat = $vat->plus($amount);
        }

        return new Totals($lineTotals, $vatByRate, $ht->toInt(), $vat->toInt(), $ht->plus($vat)->toInt());
    }

    /**
     * A rate as stored and offered (« 5,5 », « 5.5 » → « 5.50 »); null when
     * it is not a number with two decimals at most.
     */
    public static function normaliseRate(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || preg_match('/^\d{1,3}([.,]\d{1,2})?$/', trim($value)) !== 1) {
            return null;
        }

        return (string) BigDecimal::of(str_replace(',', '.', trim($value)))->toScale(2);
    }

    /** « 12.5 » → 1250 (a validated decimal with two decimals at most). */
    public static function cents(string $decimal): int
    {
        return self::hundredths($decimal)->toInt();
    }

    /** 125050 → « 1250.50 » (stored numeric(12,2)). */
    public static function decimal(int $cents): string
    {
        return (string) BigDecimal::ofUnscaledValue($cents, 2);
    }

    /** 1250050 → « 12 500,50 € ». */
    public static function display(int $cents): string
    {
        return number_format(intdiv($cents, 100), 0, ',', ' ').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).' €';
    }

    /** « 5.5 » → 550 (exact: more than two decimals is a programming error). */
    private static function hundredths(string $decimal): BigInteger
    {
        return BigDecimal::of($decimal)->toScale(2)->getUnscaledValue();
    }

    private static function rate(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(2);
    }
}
