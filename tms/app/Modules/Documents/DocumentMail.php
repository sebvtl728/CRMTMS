<?php

namespace App\Modules\Documents;

use App\Modules\Core\Companies\Company;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;
use Illuminate\Mail\Mailable;

/**
 * The e-mail of a document: subject and message typed in the window, plain
 * text only, the archived PDF attached as « <numéro>.pdf ».
 */
class DocumentMail extends Mailable
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
     * What the window proposes: the frozen recipient's e-mail and the
     * organisme's template filled in.
     *
     * @param  array<string, mixed>|null  $dossier  Paperwork::dossier(), already read for a page
     * @return array{to: string, subject: string, body: string}
     */
    public static function defaults(Document $document, ?array $dossier = null, ?string $companyName = null): array
    {
        $type = DocumentType::from($document->type);
        $dossier ??= Paperwork::dossier($document->dossier_id);
        $snapshot = $document->recipient_snapshot;
        $text = app(MailTemplates::class)->render(DocumentMailTemplates::key($type), [
            'numero' => $document->number,
            'document' => $type->label(),
            'formation' => $dossier === null ? '' : implode(' ; ', array_map(fn (array $l) => $l['label'], array_filter($dossier['lines'], fn (array $l) => $l['kind'] === 'training'))),
            'dates' => $dossier === null ? '' : implode(' ; ', array_map(fn (array $s) => DocumentPdf::period($s['starts_on'], $s['ends_on']), $dossier['sessions'])),
            'societe' => $companyName ?? Company::query()->find($document->company_id)->name ?? '',
            'destinataire' => (string) ($snapshot['name'] ?? ''),
        ]);

        return ['to' => (string) ($snapshot['email'] ?? ''), 'subject' => $text['subject'], 'body' => $text['body']];
    }
}
