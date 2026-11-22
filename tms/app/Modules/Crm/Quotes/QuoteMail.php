<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Support\CrmMailTemplates;
use Illuminate\Mail\Mailable;

/**
 * The e-mail of a quote (sending or reminder): the subject and the message
 * typed in the window, as plain text only (no HTML part: nothing typed can
 * be interpreted), with the archived PDF attached as « <numéro>.pdf ».
 */
class QuoteMail extends Mailable
{
    public const KIND_SEND = 'send';

    public const KIND_REMIND = 'remind';

    public function __construct(
        private readonly string $mailSubject,
        public readonly string $text,
        private readonly string $pdf,
        private readonly string $fileName,
        /** @var list<array{bytes: string, name: string}> brochures joined after the quote */
        private readonly array $brochures = [],
    ) {}

    public function build(): self
    {
        $mail = $this->subject($this->mailSubject)
            ->text('mail.plain-text', ['text' => $this->text])
            ->attachData($this->pdf, $this->fileName, ['mime' => 'application/pdf']);
        foreach ($this->brochures as $brochure) {
            $mail->attachData($brochure['bytes'], $brochure['name'], ['mime' => 'application/pdf']);
        }

        return $mail;
    }

    /**
     * What the window proposes: the recipient's address (as frozen once
     * sent), the subject and a message in French naming the number, the
     * company and the validity date.
     *
     * @return array{to: string, cc: list<string>, subject: string, body: string}
     */
    public static function defaults(Quote $quote, string $kind): array
    {
        $recipient = QuoteRecipient::of($quote);
        $text = app(MailTemplates::class)->render($kind === self::KIND_REMIND ? CrmMailTemplates::QUOTE_REMIND : CrmMailTemplates::QUOTE_SEND, [
            'numero_devis' => $quote->number,
            'societe' => $quote->company->name ?? '',
            'destinataire' => ($recipient['attention'] ?? null) ?: $recipient['name'],
            'montant_ttc' => str_replace("\u{00A0}", ' ', QuotePdf::money($quote->total_ttc)),
            'date_validite' => $quote->valid_until->format('d/m/Y'),
            'commercial' => $quote->opportunity?->responsible->name ?? '',
            'organisme' => app(TenantContext::class)->current()->name ?? '',
        ]);

        return [
            'to' => $recipient['email'] ?? '',
            'cc' => [],
            'subject' => $text['subject'],
            'body' => $text['body'],
        ];
    }
}
