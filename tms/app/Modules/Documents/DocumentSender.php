<?php

namespace App\Modules\Documents;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * « Envoyer » an issued document by e-mail: one DocumentSending per request
 * (address, subject, message), queued after commit as SendDocument, under
 * the lock of the document; its email_status becomes « pending ». Only the
 * archived PDF ever leaves (410 when lost or altered). The journal names
 * the sending, never the address.
 */
final class DocumentSender
{
    public const CANCELLED = 'Ce document est annulé : il ne peut pas être envoyé.';

    public const STILL_SENDING = 'Un envoi est déjà en cours pour ce document.';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $message  to, subject, body
     */
    public function send(?User $user, Document $document, array $message): DocumentSending
    {
        $values = Validator::make($message, [
            'to' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ], [
            'to.required' => 'Indiquez l\'adresse du destinataire.',
            'to.email' => 'Adresse e-mail invalide.',
            'subject.required' => 'Indiquez l\'objet.',
            'body.required' => 'Indiquez le message.',
            'max' => 'Ce texte est trop long.',
        ])->validate();

        return DB::transaction(function () use ($user, $document, $values): DocumentSending {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::CANCELLED);
            }
            if ($locked->email_status === 'pending') {
                throw new UserFacingHttpException(409, self::STILL_SENDING);
            }
            DocumentPdf::archived($locked);

            $sending = DocumentSending::query()->create([
                'document_id' => $locked->id,
                'email' => $values['to'],
                'subject' => $values['subject'],
                'body' => $values['body'],
                'status' => 'pending',
                'sent_by' => $user?->id,
            ]);
            Document::query()->whereKey($locked->id)->toBase()
                ->update(['email_status' => 'pending', 'email_error_code' => null, 'updated_at' => CarbonImmutable::now()]);
            $this->audit->record('document.email_queued', $locked, [], ['sending' => $sending->id], $user);
            SendDocument::dispatch($sending->id)->afterCommit();

            $document->setRawAttributes(Document::query()->findOrFail($locked->id)->getAttributes(), true);

            return $sending;
        });
    }
}
