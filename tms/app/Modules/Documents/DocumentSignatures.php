<?php

namespace App\Modules\Documents;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Signatures\Events\SignatureCompleted;
use App\Modules\Core\Signatures\SignatureRequest;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Core\Users\User;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Online signature of a convention, a contract or a trainee's form
 * (Core\Signatures): the archived PDF is what is signed; the document waits
 * « En attente de signature », then « Signé » and, for a document of the
 * dossier (convention, contract), its dossier « Convention signée ».
 */
final class DocumentSignatures
{
    public const SUBJECT = Document::MORPH_ALIAS;

    public function __construct(private readonly Signatures $signatures) {}

    public function request(User $by, Document $document, string $email): SignatureRequest
    {
        Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:254']],
            ['email.required' => 'Indiquez l\'adresse du signataire.', 'email.email' => 'Adresse e-mail invalide.'])->validate();
        $type = DocumentType::from($document->type);
        if (! $type->signable()) {
            throw new UserFacingHttpException(409, 'Ce document ne se signe pas en ligne.');
        }
        if ($document->isCancelled() || $document->status === DocumentStatus::Signed) {
            throw new UserFacingHttpException(409, $document->isCancelled() ? 'Ce document est annulé : il ne peut pas être signé.' : 'Ce document est déjà signé.');
        }
        DocumentPdf::archived($document);
        $snapshot = $document->recipient_snapshot;

        $result = $this->signatures->request(self::SUBJECT, $document->id, $document->pdf_path, $document->pdf_sha256,
            (string) ($snapshot['attention'] ?? $snapshot['name'] ?? ''), $email, $snapshot['person_id'] ?? null,
            $type->label().' '.$document->number, $by);
        Document::query()->whereKey($document->id)->toBase()->update(['status' => DocumentStatus::SignaturePending->value, 'updated_at' => CarbonImmutable::now()]);

        return $result['request'];
    }

    /** SignatureCompleted of a document: « Signé », dossier « Convention signée ». */
    public static function completed(SignatureCompleted $event, Paperwork $paperwork): void
    {
        if ($event->subjectType !== self::SUBJECT) {
            return;
        }
        $document = DB::transaction(function () use ($event): ?Document {
            $document = Document::query()->lockForUpdate()->find($event->subjectId);
            if ($document === null || $document->isCancelled()) {
                return null;
            }
            Document::query()->whereKey($document->id)->toBase()->update(['status' => DocumentStatus::Signed->value, 'updated_at' => CarbonImmutable::now()]);

            return $document;
        });
        // A trainee's form (individual contract, rules, image rights) never says anything of the dossier.
        if ($document !== null && ! DocumentType::from($document->type)->perEnrolment()) {
            $paperwork->markConventionSigned($document->dossier_id, $event->signedAt->setTimezone('Europe/Paris'));
        }
    }

    /**
     * The state of the online signature of a document, for its block.
     *
     * @return array{status: string, signed_at: ?string, email: ?string}|null
     */
    public static function state(Document $document, ?SignatureRequest $request = null, bool $preloaded = false): ?array
    {
        $request = $preloaded ? $request : Signatures::latestFor(self::SUBJECT, $document->id);

        return $request === null ? null : [
            'status' => $request->status,
            'signed_at' => $request->signed_at?->toIso8601String(),
            'email' => $request->signer_email,
        ];
    }
}
