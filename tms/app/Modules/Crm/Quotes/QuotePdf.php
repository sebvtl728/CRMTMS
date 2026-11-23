<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Companies\LegalMentions;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Documents\PdfRenderingFailed;
use App\Modules\Core\Documents\PdfSource;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The PDF of a quote: HTML from the Blade template documents/quote (every
 * typed text escaped, no external resource), converted by PdfRenderer.
 *
 * A draft is previewed on the fly with a « BROUILLON » watermark; a sent
 * quote is archived once (QuoteFreezer) under
 * tenants/<tenant_id>/quotes/<quote_id>/<number>.pdf and that file is served.
 */
class QuotePdf
{
    private const NBSP = "\u{00A0}";

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly CompanyBranding $branding,
    ) {}

    /**
     * @param  bool|null  $watermark  default: a draft is watermarked
     *
     * @throws PdfRenderingFailed
     */
    public function render(Quote $quote, ?bool $watermark = null): string
    {
        return $this->renderer->fromHtml($this->html($quote, $watermark), $this->footerHtml($quote));
    }

    /**
     * Renders the final document (no watermark) and stores it; returns its path.
     *
     * @throws PdfRenderingFailed
     */
    public function archive(Quote $quote): string
    {
        $html = $this->html($quote, false);
        $footer = $this->footerHtml($quote);
        $bytes = $this->renderer->fromHtml($html, $footer);
        $path = self::archivePath($quote);

        if (! Storage::disk(TenantStorage::DISK)->put($path, $bytes)) {
            throw new RuntimeException('The quote PDF could not be written to the private disk.');
        }
        // Its exact HTML: a « Bon pour accord » signed online renders it again with the drawing in the box.
        PdfSource::store($path, $html, $footer);

        return $path;
    }

    /**
     * Path of the archived PDF when it exists, else null (a draft never has one).
     */
    public function archived(Quote $quote): ?string
    {
        $path = $quote->pdf_path;
        if ($quote->isDraft() || $path === null || $path !== self::archivePath($quote)) {
            return null;
        }

        return Storage::disk(TenantStorage::DISK)->exists($path) ? $path : null;
    }

    public static function archivePath(Quote $quote): string
    {
        return TenantStorage::path('quotes', $quote->id.'/'.self::fileName($quote));
    }

    /** « CF-2026-0001.pdf » (numbers are [A-Z0-9-] only). */
    public static function fileName(Quote $quote): string
    {
        return preg_replace('/[^A-Za-z0-9-]/', '_', $quote->number).'.pdf';
    }

    public function html(Quote $quote, ?bool $watermark = null): string
    {
        $quote->loadMissing(['company', 'opportunity', 'organisation', 'person', 'contact', 'lines']);
        $company = $quote->company;
        assert($company !== null);

        $totals = QuoteTotals::compute(
            $quote->lines->map(fn (QuoteLine $l) => new LineInput($l->quantity, $l->unit_price, $l->discount_percent, $l->vat_rate))->values()->all(),
            $quote->vat_exempt,
        );

        return view('documents.quote', [
            'watermark' => $watermark ?? $quote->isDraft(),
            'company' => [
                'name' => $company->name,
                'logo' => $this->branding->logoDataUri($company),
                'address' => array_values(array_filter([
                    $company->address_line1,
                    $company->address_line2,
                    trim(($company->address_postal_code ?? '').' '.($company->address_city ?? '')),
                ], fn (?string $line) => $line !== null && trim($line) !== '')),
                'email' => $company->email,
                'phone' => $company->phone,
                'vat_number' => $company->vat_number,
                'nda_number' => $company->nda_number,
                'legal' => LegalMentions::for($company),
                'terms' => $company->quote_terms,
                'footer' => $company->quote_footer,
            ],
            'quote' => [
                'number' => $quote->number,
                'issued_on' => $quote->issued_on->format('d/m/Y'),
                'valid_until' => $quote->valid_until->format('d/m/Y'),
                'subject' => $quote->opportunity?->title,
                'notes' => $quote->notes,
                'funding' => $quote->fundingMode?->label,
                'vat_exempt' => $quote->vat_exempt,
                'vat_notice' => $quote->vat_exempt ? LegalMentions::vatExemptionNotice($quote->issued_on) : null,
            ],
            'recipient' => QuoteRecipient::of($quote),
            'hasDiscount' => $quote->lines->contains(fn (QuoteLine $l) => (float) $l->discount_percent > 0),
            'lines' => $quote->lines->map(fn (QuoteLine $l) => [
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => self::number($l->quantity),
                'unit' => QuoteLine::UNITS[$l->unit] ?? $l->unit,
                'unit_price' => self::money($l->unit_price),
                'discount' => (float) $l->discount_percent > 0 ? self::percent($l->discount_percent) : '',
                'vat_rate' => self::percent($l->vat_rate),
                'total_ht' => self::money($l->total_ht),
            ])->values()->all(),
            'vatByRate' => array_map(fn (array $r) => [
                'rate' => self::percent($r['rate']),
                'base' => self::moneyCents($r['base']),
                'vat' => self::moneyCents($r['vat']),
            ], $totals->vatByRate),
            'totals' => [
                'ht' => self::money($quote->total_ht),
                'vat' => self::money($quote->total_vat),
                'ttc' => self::money($quote->total_ttc),
            ],
        ])->render();
    }

    /**
     * Repeated at the bottom of every page: number and « page x / y ».
     */
    public function footerHtml(Quote $quote): string
    {
        return view('documents.quote-footer', ['number' => $quote->number])->render();
    }

    /** « 12500.00 » → « 12 500,00 € » (U+00A0: never wraps). */
    public static function money(string $decimal): string
    {
        return self::moneyCents(QuoteTotals::cents($decimal));
    }

    private static function moneyCents(int $cents): string
    {
        return str_replace(' ', self::NBSP, QuoteTotals::display($cents));
    }

    /** « 3.00 » → « 3 », « 2.50 » → « 2,5 ». */
    private static function number(string $decimal): string
    {
        $value = str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;

        return str_replace('.', ',', $value);
    }

    /** « 5.50 » → « 5,5 % ». */
    private static function percent(string $decimal): string
    {
        return self::number($decimal).self::NBSP.'%';
    }
}
