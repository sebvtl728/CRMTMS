<?php

namespace App\Modules\Crm\Quotes;

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
        private readonly string $text,
        private readonly string $pdf,
        private readonly string $fileName,
    ) {}

    public function build(): self
    {
        return $this->subject($this->mailSubject)
            ->text('mail.quote', ['text' => $this->text])
            ->attachData($this->pdf, $this->fileName, ['mime' => 'application/pdf']);
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
        $company = $quote->company->name ?? '';
        $validUntil = $quote->valid_until->format('d/m/Y');

        if ($kind === self::KIND_REMIND) {
            $subject = "Relance : devis {$quote->number} — {$company}";
            $body = "Bonjour,\n\nNous revenons vers vous au sujet de notre devis {$quote->number}, valable jusqu'au {$validUntil}, que vous trouverez de nouveau ci-joint.\n\nN'hésitez pas à nous contacter pour toute question.\n\nCordialement,\n{$company}";
        } else {
            $subject = "Devis {$quote->number} — {$company}";
            $body = "Bonjour,\n\nVeuillez trouver ci-joint notre devis {$quote->number}, valable jusqu'au {$validUntil}.\n\nNous restons à votre disposition pour toute question.\n\nCordialement,\n{$company}";
        }

        return [
            'to' => QuoteRecipient::of($quote)['email'] ?? '',
            'cc' => [],
            'subject' => $subject,
            'body' => $body,
        ];
    }
}
