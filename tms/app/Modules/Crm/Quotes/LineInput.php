<?php

namespace App\Modules\Crm\Quotes;

/**
 * The figures of one quote line, as validated decimal strings (« 2.5 »,
 * « 1234.57 »): at most two decimals, never floats.
 */
final readonly class LineInput
{
    public function __construct(
        public string $quantity,
        public string $unitPrice,
        public string $discountPercent,
        public string $vatRate,
    ) {}
}
