<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Mail\Mailable;

/**
 * The e-mail of an invoice or a credit note: the subject and the message
 * typed in the window, as plain text only (nothing typed can be
 * interpreted), with the archived PDF attached as « <numéro>.pdf ».
 */
class InvoiceMail extends Mailable
{
    public function __construct(
        private readonly string $mailSubject,
        private readonly string $text,
        private readonly string $pdf,
        private readonly string $fileName,
    ) {}

    public function build(): self
    {
        return $this->subject($this->mailSubject)
            ->text('mail.plain-text', ['text' => $this->text])
            ->attachData($this->pdf, $this->fileName, ['mime' => 'application/pdf']);
    }

    /**
     * What the window proposes: the e-mail of the recipient as frozen at
     * validation, the subject and a message in French naming the number, the
     * amount and the due date.
     *
     * @return array{to: string, subject: string, body: string}
     */
    public static function defaults(Invoice $invoice): array
    {
        $snapshot = $invoice->recipient_snapshot ?? [];
        $credit = $invoice->kind === Invoice::CREDIT_NOTE;
        $text = app(MailTemplates::class)->render(FinanceMailTemplates::INVOICE, [
            'numero' => (string) $invoice->number,
            'piece' => $invoice->kindLabel(),
            'type' => $credit ? 'avoir' : 'facture',
            'societe' => $invoice->company->name ?? '',
            'destinataire' => (string) ($snapshot['name'] ?? ''),
            'montant_ttc' => Money::format($invoice->total_ttc_cents),
            'echeance' => $invoice->due_on?->format('d/m/Y') ?? '',
            'reglement' => $credit || $invoice->due_on === null ? '' : ' À régler avant le '.$invoice->due_on->format('d/m/Y').'.',
            'organisme' => app(TenantContext::class)->current()->name ?? '',
        ]);

        return ['to' => (string) ($snapshot['email'] ?? ''), 'subject' => $text['subject'], 'body' => $text['body']];
    }
}
