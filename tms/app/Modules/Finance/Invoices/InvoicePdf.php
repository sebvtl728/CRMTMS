<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Companies\LegalMentions;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Documents\PdfRenderingFailed;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The PDF of an invoice or a credit note (spec 0.9 § 4.4), in the charter
 * of the quotes: HTML from documents/invoice (every typed text escaped, no
 * external resource, logo as a data URI), converted by PdfRenderer.
 *
 * A draft is previewed on the fly with a « BROUILLON » watermark; a
 * validated one is rendered once, inside its validation, and archived
 * under tenants/<tenant_id>/invoices/<invoice_id>/<number>.pdf - that file
 * is the invoice, served and sent ever after.
 */
class InvoicePdf
{
    private const NBSP = "\u{00A0}";

    /** Late payment, owed whatever the terms (Code de commerce L441-10, D441-5). */
    public const PENALTIES = 'En cas de retard de paiement, des pénalités de retard sont exigibles au taux de trois fois le taux d\'intérêt légal, ainsi qu\'une indemnité forfaitaire pour frais de recouvrement de 40 € (articles L441-10 et D441-5 du Code de commerce).';

    public const NO_DISCOUNT = 'Pas d\'escompte pour paiement anticipé.';

    /** Required on the documents of a training body (Code du travail R6351-6). */
    public const NOT_AN_APPROVAL = 'Cet enregistrement ne vaut pas agrément de l\'État.';

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly CompanyBranding $branding,
    ) {}

    /**
     * @throws PdfRenderingFailed
     */
    public function render(Invoice $invoice): string
    {
        return $this->renderer->fromHtml($this->html($invoice), $this->footerHtml($invoice));
    }

    /**
     * Renders the final document and stores it; returns its path and the
     * SHA-256 of its bytes.
     *
     * @return array{path: string, sha256: string}
     *
     * @throws PdfRenderingFailed
     */
    public function archive(Invoice $invoice): array
    {
        $bytes = $this->render($invoice);
        $path = self::archivePath($invoice);
        if (! Storage::disk(TenantStorage::DISK)->put($path, $bytes)) {
            throw new RuntimeException('The invoice PDF could not be written to the private disk.');
        }

        return ['path' => $path, 'sha256' => hash('sha256', $bytes)];
    }

    /**
     * Path of the archived PDF when it exists, else null (a draft never has one).
     */
    public function archived(Invoice $invoice): ?string
    {
        $path = $invoice->pdf_path;
        if ($invoice->isDraft() || $path === null || $path !== self::archivePath($invoice)) {
            return null;
        }

        return Storage::disk(TenantStorage::DISK)->exists($path) ? $path : null;
    }

    public static function archivePath(Invoice $invoice): string
    {
        return TenantStorage::path('invoices', $invoice->id.'/'.self::fileName($invoice));
    }

    /** « F-2026-00001.pdf »; a draft: « brouillon-<id>.pdf ». */
    public static function fileName(Invoice $invoice): string
    {
        return $invoice->number === null
            ? 'brouillon-'.$invoice->id.'.pdf'
            : preg_replace('/[^A-Za-z0-9-]/', '_', $invoice->number).'.pdf';
    }

    public function html(Invoice $invoice): string
    {
        $company = Company::withTrashed()->findOrFail($invoice->company_id);
        $lines = $invoice->lines()->get();
        $exempt = InvoiceTotals::exempt($invoice, $company);
        $credit = $invoice->kind === Invoice::CREDIT_NOTE;
        $corrected = $invoice->corrected_invoice_id === null ? null : Invoice::query()->find($invoice->corrected_invoice_id);

        return view('documents.invoice', [
            'watermark' => $invoice->isDraft(),
            'title' => $credit ? 'AVOIR' : 'FACTURE',
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
                'siret' => $company->siret,
                'vat_number' => $company->vat_number,
                'nda_number' => $company->nda_number,
                'nda_notice' => self::NOT_AN_APPROVAL,
                'legal' => LegalMentions::for($company),
                'footer' => $company->invoice_footer,
            ],
            'invoice' => [
                'number' => $invoice->number ?? '-',
                'issued_on' => $invoice->issued_on?->format('d/m/Y') ?? '-',
                'due_on' => $invoice->due_on?->format('d/m/Y'),
                'period' => $invoice->period_from !== null && $invoice->period_to !== null
                    ? 'du '.$invoice->period_from->format('d/m/Y').' au '.$invoice->period_to->format('d/m/Y') : null,
                'corrected' => $corrected === null ? null : [
                    'number' => $corrected->number ?? '-',
                    'issued_on' => $corrected->issued_on?->format('d/m/Y'),
                ],
                'vat_mention' => $invoice->vat_mention,
                'exempt' => $exempt,
            ],
            'recipient' => InvoiceRecipient::of($invoice),
            'lines' => $lines->map(fn (InvoiceLine $l) => [
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => self::number((string) $l->quantity),
                'unit' => $l->unit,
                'unit_price' => self::money($l->unit_price_cents),
                'vat_rate' => self::percent((string) $l->vat_rate),
                'total_ht' => self::money($l->total_ht_cents),
            ])->values()->all(),
            'vatByRate' => array_map(fn (array $r) => [
                'rate' => self::percent($r['rate']),
                'base' => self::money($r['base']),
                'vat' => self::money($r['vat']),
            ], InvoiceTotals::byRate($lines)),
            'totals' => [
                'ht' => self::money($invoice->total_ht_cents),
                'vat' => self::money($invoice->total_vat_cents),
                'ttc' => self::money($invoice->total_ttc_cents),
            ],
            'payment' => $credit ? null : [
                'terms' => $company->payment_terms_days === 0
                    ? 'Paiement à réception de la facture.'
                    : 'Paiement à '.$company->payment_terms_days.' jours'.($invoice->due_on !== null ? ', au plus tard le '.$invoice->due_on->format('d/m/Y') : '').'.',
                'no_discount' => self::NO_DISCOUNT,
                // Late penalties and the 40 € indemnity concern professionals only (Code de commerce).
                'penalties' => $invoice->recipient_type === 'organisation' ? self::PENALTIES : null,
            ],
        ])->render();
    }

    /**
     * Repeated at the bottom of every page: number and « page x / y ».
     */
    public function footerHtml(Invoice $invoice): string
    {
        return view('documents.invoice-footer', [
            'title' => ($invoice->kind === Invoice::CREDIT_NOTE ? 'Avoir' : 'Facture').' '.($invoice->number ?? '(brouillon)'),
        ])->render();
    }

    private static function money(int $cents): string
    {
        return Money::format($cents);
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
