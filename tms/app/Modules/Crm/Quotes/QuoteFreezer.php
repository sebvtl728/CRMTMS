<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Documents\PdfRenderingFailed;
use LogicException;

/**
 * Freezes a quote being sent, inside the caller's transaction: the recipient
 * is copied (recipient_snapshot), vat_exempt stays as stored, and the PDF is
 * rendered and archived (pdf_path). From then on the archived PDF is served,
 * whatever later changes the company or the fiches.
 */
class QuoteFreezer
{
    public function __construct(private readonly QuotePdf $pdf) {}

    /**
     * @throws LogicException when the quote is already sent (frozen once)
     * @throws PdfRenderingFailed
     */
    public function freeze(Quote $quote): void
    {
        if (! $quote->isDraft()) {
            throw new LogicException('Only a draft quote is frozen.');
        }

        $quote->recipient_snapshot = QuoteRecipient::current($quote);
        $quote->pdf_path = $this->pdf->archive($quote);
        $quote->save();
    }
}
