<?php

namespace App\Modules\Crm\Quotes;

/**
 * Amounts of a quote in integer cents (QuoteTotals::compute).
 */
final readonly class Totals
{
    /**
     * @param  list<int>  $lines  HT of each line, rounded, in the order given
     * @param  list<array{rate: string, base: int, vat: int}>  $vatByRate  one row per rate (« 20.00 »), highest rate first
     */
    public function __construct(
        public array $lines,
        public array $vatByRate,
        public int $ht,
        public int $vat,
        public int $ttc,
    ) {}

    /** Every amount fits a numeric(12,2) column. */
    public function fits(): bool
    {
        return $this->ttc <= QuoteTotals::MAX_CENTS;
    }
}
