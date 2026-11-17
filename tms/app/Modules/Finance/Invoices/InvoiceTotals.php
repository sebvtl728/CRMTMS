<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\VatRegime;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * The amounts of an invoice in integer cents (brick/math, half up, never a
 * float): a line = round(quantity × unit price); HT = Σ lines; VAT per
 * rate on the sum of its lines (as the quotes); TTC = HT + VAT. The VAT
 * mention follows the company and the lines (spec 0.9 § 4.2, § 4 bis 1).
 */
final class InvoiceTotals
{
    public const EXEMPTION = 'Exonération de TVA, article 261-4-4° du CGI';

    public const DEDIT_OUT_OF_SCOPE = 'Indemnité hors champ de la TVA';

    /** Rate of a line of a company subject to VAT, unless changed. */
    public const DEFAULT_RATE = '20.00';

    /** Rates offered on a line. */
    public const RATES = ['0.00', '5.50', '10.00', '20.00'];

    public static function line(string $quantity, int $unitPriceCents): int
    {
        return BigDecimal::of($quantity)->multipliedBy($unitPriceCents)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** The rate of a line of $component for $company (exempt: 0; the dédit never bears VAT). */
    public static function rateFor(Company $company, ?string $component, ?string $wanted = null): string
    {
        if ($company->vat_regime === VatRegime::Exempt || $component === InvoiceLine::DEDIT) {
            return '0.00';
        }

        return $wanted ?? self::DEFAULT_RATE;
    }

    /**
     * Totals and VAT mention of the invoice from its lines (saved by the caller).
     */
    public static function apply(Invoice $invoice, Company $company): void
    {
        $lines = $invoice->lines()->get();
        $ht = 0;
        /** @var array<string, int> $byRate */
        $byRate = [];
        $dedit = false;
        foreach ($lines as $line) {
            $ht += $line->total_ht_cents;
            $rate = (string) $line->vat_rate;
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $line->total_ht_cents;
            $dedit = $dedit || ($line->component === InvoiceLine::DEDIT && $line->total_ht_cents !== 0);
        }
        $vat = 0;
        foreach ($byRate as $rate => $sum) {
            $vat += BigInteger::of($sum)->toBigDecimal()->multipliedBy($rate)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
        }
        $mentions = array_values(array_filter([
            $company->vat_regime === VatRegime::Exempt ? self::EXEMPTION : null,
            $dedit ? self::DEDIT_OUT_OF_SCOPE : null,
        ]));

        $invoice->forceFill([
            'total_ht_cents' => $ht,
            'total_vat_cents' => $vat,
            'total_ttc_cents' => $ht + $vat,
            'vat_mention' => $mentions === [] ? null : implode("\n", $mentions),
        ]);
    }
}
