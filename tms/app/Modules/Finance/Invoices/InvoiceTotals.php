<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\LegalMentions;
use App\Modules\Core\Companies\VatRegime;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * The amounts of an invoice in integer cents (brick/math, half up, never a
 * float): a line = round(quantity × unit price); HT = Σ lines; VAT per
 * rate on the sum of its lines (as the quotes); TTC = HT + VAT. The VAT
 * mention follows the company — or, for a credit note, the invoice it
 * corrects — and the lines (spec 0.9 § 4.2, § 4 bis 1).
 */
final class InvoiceTotals
{
    public const DEDIT_OUT_OF_SCOPE = 'Indemnité hors champ de la TVA';

    /** Rate of a line of a company subject to VAT, unless changed. */
    public const DEFAULT_RATE = '20.00';

    /** Rates offered on a line. */
    public const RATES = ['0.00', '5.50', '10.00', '20.00'];

    public static function line(string $quantity, int $unitPriceCents): int
    {
        return BigDecimal::of($quantity)->multipliedBy($unitPriceCents)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * Whether the invoice is exempt from VAT: a credit note follows the
     * invoice it corrects (its regime as printed, whatever the company's
     * settings today); anything else the company's regime.
     */
    public static function exempt(Invoice $invoice, Company $company): bool
    {
        if ($invoice->corrected_invoice_id !== null) {
            $corrected = Invoice::query()->find($invoice->corrected_invoice_id);
            if ($corrected !== null && ! $corrected->isDraft()) {
                return LegalMentions::isVatExemption($corrected->vat_mention);
            }
        }

        return $company->vat_regime === VatRegime::Exempt;
    }

    /** The rate of a line of $component ($exempt: 0; the dédit never bears VAT). */
    public static function rateFor(bool $exempt, ?string $component, ?string $wanted = null): string
    {
        if ($exempt || $component === InvoiceLine::DEDIT) {
            return '0.00';
        }

        return $wanted ?? self::DEFAULT_RATE;
    }

    /**
     * Base and VAT per rate (« 20.00 »), sorted by rate: VAT = round(base ×
     * rate / 100), half up, on the sum of the lines of that rate.
     *
     * @param  iterable<InvoiceLine>  $lines
     * @return list<array{rate: string, base: int, vat: int}>
     */
    public static function byRate(iterable $lines): array
    {
        /** @var array<string, int> $bases */
        $bases = [];
        foreach ($lines as $line) {
            $rate = (string) $line->vat_rate;
            $bases[$rate] = ($bases[$rate] ?? 0) + $line->total_ht_cents;
        }
        ksort($bases, SORT_STRING);
        $rows = [];
        foreach ($bases as $rate => $base) {
            $rate = (string) $rate;
            $rows[] = ['rate' => $rate, 'base' => $base, 'vat' => BigInteger::of($base)->toBigDecimal()->multipliedBy($rate)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt()];
        }

        return $rows;
    }

    /**
     * Totals and VAT mention of the invoice from its lines (saved by the caller).
     */
    public static function apply(Invoice $invoice, Company $company): void
    {
        $lines = $invoice->lines()->get();
        $ht = (int) $lines->sum('total_ht_cents');
        $vat = array_sum(array_column(self::byRate($lines), 'vat'));
        $dedit = $lines->contains(fn (InvoiceLine $l) => $l->component === InvoiceLine::DEDIT && $l->total_ht_cents !== 0);
        $mentions = array_values(array_filter([
            // The notice of the invoice's day (its validation day; today for a draft).
            self::exempt($invoice, $company) ? LegalMentions::vatExemptionNotice($invoice->issued_on ?? null) : null,
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
