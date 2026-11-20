<?php

namespace App\Modules\Documents;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Billing;
use App\Modules\Sessions\Public\Paperwork;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Issues a training document of a dossier and cancels one. Issuing, in one
 * transaction under the lock of the dossier: checks, number, rendering
 * (a Gotenberg failure rolls back: no number used, no file left), archive,
 * row, journal. An issued document never changes; a mistake is cancelled
 * (with a reason) and a new one issued. The journal entries are those of
 * the Auditable model (creation, cancellation), without personal values.
 */
class DocumentIssuer
{
    public const CONVENTION_FOR_COMPANY = 'La convention concerne un contractant entreprise : pour un particulier, émettez un contrat.';

    public const CONTRACT_FOR_INDIVIDUAL = 'Le contrat concerne un particulier : pour une entreprise, émettez une convention.';

    public function __construct(
        private readonly DocumentNumbering $numbering,
        private readonly DocumentPdf $pdf,
        private readonly Fiches $fiches,
    ) {}

    public function issue(User $by, DocumentType $type, string $dossierId): Document
    {
        if ($type->perEnrolment()) {
            throw new \InvalidArgumentException("{$type->value} is a document of a trainee.");
        }

        return DB::transaction(function () use ($by, $type, $dossierId): Document {
            Billing::lockDossier($dossierId);
            $dossier = Paperwork::dossier($dossierId) ?? throw new UserFacingHttpException(404, 'Dossier introuvable.');
            $this->check($type, $dossier);

            $company = Company::query()->find($dossier['company_id']) ?? throw new UserFacingHttpException(409, 'Ce dossier n\'a pas de société émettrice.');
            $recipient = $this->recipient($type, $dossier);
            $issuedAt = CarbonImmutable::now();
            $number = $this->numbering->next($issuedAt);
            $content = $this->pdf->render($type, $dossier, $company, $number, $issuedAt, $recipient);

            return $this->store($by, $type, $dossierId, null, $company, $number, $issuedAt, $recipient, $content);
        });
    }

    /**
     * A per-trainee document (convocation, attestation, certificate) of an
     * enrolment, under the lock of its dossier.
     */
    public function issueForEnrolment(User $by, DocumentType $type, string $enrolmentId): Document
    {
        if (! $type->perEnrolment()) {
            throw new \InvalidArgumentException("{$type->value} is a document of the dossier.");
        }
        $first = Paperwork::enrolment($enrolmentId) ?? throw new UserFacingHttpException(404, 'Inscription introuvable.');

        return DB::transaction(function () use ($by, $type, $enrolmentId, $first): Document {
            Billing::lockDossier($first['dossier_id']);
            $enrolment = Paperwork::enrolment($enrolmentId) ?? throw new UserFacingHttpException(404, 'Inscription introuvable.');
            $dossier = Paperwork::dossier($enrolment['dossier_id']) ?? throw new UserFacingHttpException(404, 'Dossier introuvable.');
            $this->checkEnrolment($type, $enrolment, $dossier);

            $company = Company::query()->find($dossier['company_id']) ?? throw new UserFacingHttpException(409, 'Ce dossier n\'a pas de société émettrice.');
            $recipient = $this->traineeRecipient($enrolment['person_id']);
            $issuedAt = CarbonImmutable::now();
            $number = $this->numbering->next($issuedAt);
            $content = $this->pdf->renderForEnrolment($type, $enrolment, $dossier, $company, $number, $issuedAt, $recipient);

            return $this->store($by, $type, $dossier['id'], $enrolmentId, $company, $number, $issuedAt, $recipient, $content);
        });
    }

    /**
     * @param  array<string, mixed>  $recipient
     */
    private function store(User $by, DocumentType $type, string $dossierId, ?string $enrolmentId, Company $company, string $number, CarbonImmutable $issuedAt, array $recipient, string $content): Document
    {
        $path = TenantStorage::path('documents', $issuedAt->setTimezone('Europe/Paris')->format('Y').'/'.Str::uuid7().'.pdf');
        $disk = Storage::disk(TenantStorage::DISK);
        $disk->put($path, $content);
        try {
            return Document::query()->create([
                'type' => $type->value,
                'dossier_id' => $dossierId,
                'enrolment_id' => $enrolmentId,
                'company_id' => $company->id,
                'number' => $number,
                'recipient_snapshot' => $recipient,
                'pdf_path' => $path,
                'pdf_sha256' => hash('sha256', $content),
                'status' => DocumentStatus::Issued,
                'issued_at' => $issuedAt,
                'issued_by' => $by->id,
            ]);
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $enrolment
     * @param  array<string, mixed>  $dossier
     */
    private function checkEnrolment(DocumentType $type, array $enrolment, array $dossier): void
    {
        if ($dossier['trashed'] || $dossier['status'] === 'cancelled') {
            throw new UserFacingHttpException(409, 'Ce dossier est annulé ou à la corbeille : aucun document ne peut être émis.');
        }
        if ($enrolment['session']['cancelled']) {
            throw new UserFacingHttpException(409, 'Cette session est annulée : aucun document ne peut être émis.');
        }
        if (! in_array($enrolment['status'], ['enrolled', 'confirmed', 'abandoned'], true)) {
            throw new UserFacingHttpException(409, 'Ce stagiaire n\'est pas inscrit : aucun document ne peut être émis.');
        }
        $ended = $enrolment['session']['ends_on'] <= CarbonImmutable::now('Europe/Paris')->toDateString();
        if ($type !== DocumentType::Convocation && ! $ended && $enrolment['left_on'] === null) {
            throw new UserFacingHttpException(409, 'L\'attestation et le certificat s\'émettent à la fin de la formation ou à la sortie du stagiaire.');
        }
        $living = Document::query()->where('enrolment_id', $enrolment['id'])->where('type', $type->value)
            ->where('status', '!=', DocumentStatus::Cancelled->value)->exists();
        if ($living) {
            throw new UserFacingHttpException(409, match ($type) {
                DocumentType::Convocation => 'Une convocation est déjà émise pour ce stagiaire : annulez-la avant d\'en émettre une nouvelle.',
                DocumentType::CompletionCertificate => 'Une attestation est déjà émise pour ce stagiaire : annulez-la avant d\'en émettre une nouvelle.',
                default => 'Un certificat de réalisation est déjà émis pour ce stagiaire : annulez-le avant d\'en émettre un nouveau.',
            });
        }
    }

    /**
     * @return array{name: string, attention: null, address_line1: ?string, address_line2: ?string, postal_code: ?string, city: ?string, email: ?string, person_id: string}
     */
    private function traineeRecipient(string $personId): array
    {
        $identity = $this->fiches->billingIdentity('person', $personId)
            ?? throw new UserFacingHttpException(409, 'Ce stagiaire est introuvable ou anonymisé.');

        return [
            'name' => $identity['name'],
            'attention' => null,
            'address_line1' => $identity['address_line1'],
            'address_line2' => $identity['address_line2'],
            'postal_code' => $identity['postal_code'],
            'city' => $identity['city'],
            'email' => $this->fiches->personEmails([$personId])[$personId] ?? null,
            'person_id' => $personId,
        ];
    }

    public function cancel(User $by, Document $document, string $reason): Document
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Indiquez le motif de l\'annulation.']);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Le motif est trop long (500 caractères au plus).']);
        }

        return DB::transaction(function () use ($by, $document, $reason): Document {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, 'Ce document est déjà annulé.');
            }
            $locked->status = DocumentStatus::Cancelled;
            $locked->forceFill(['cancelled_at' => CarbonImmutable::now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason])->save();
            Signatures::cancelFor(Document::MORPH_ALIAS, $locked->id);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $dossier
     */
    private function check(DocumentType $type, array $dossier): void
    {
        if ($dossier['trashed']) {
            throw new UserFacingHttpException(409, 'Ce dossier est à la corbeille : aucun document ne peut être émis.');
        }
        if ($dossier['status'] === 'cancelled') {
            throw new UserFacingHttpException(409, 'Ce dossier est annulé : aucun document ne peut être émis.');
        }
        $contractor = $dossier['contractor']['type'];
        if ($type === DocumentType::Convention && $contractor !== 'organisation') {
            throw new UserFacingHttpException(409, self::CONVENTION_FOR_COMPANY);
        }
        if ($type === DocumentType::Contract && $contractor !== 'person') {
            throw new UserFacingHttpException(409, self::CONTRACT_FOR_INDIVIDUAL);
        }
        $living = Document::query()->where('dossier_id', $dossier['id'])->where('type', $type->value)
            ->where('status', '!=', DocumentStatus::Cancelled->value)->exists();
        if ($living) {
            throw new UserFacingHttpException(409, $type === DocumentType::Convention
                ? 'Une convention est déjà émise : annulez-la avant d\'en émettre une nouvelle.'
                : 'Un contrat est déjà émis : annulez-le avant d\'en émettre un nouveau.');
        }
    }

    /**
     * Who the document is addressed to, frozen on it: the contractor's name
     * and address; for a convention, the dossier's contact (attention, e-mail).
     *
     * @param  array<string, mixed>  $dossier
     *                                         person_id: the person named on it (the particulier, or the contact of
     *                                         a convention), for the GDPR anonymisation and export.
     * @return array{name: string, attention: ?string, address_line1: ?string, address_line2: ?string, postal_code: ?string, city: ?string, email: ?string, person_id: ?string}
     */
    private function recipient(DocumentType $type, array $dossier): array
    {
        $contractor = $dossier['contractor'];
        $identity = $this->fiches->billingIdentity($contractor['type'], $contractor['id'])
            ?? throw new UserFacingHttpException(409, 'Le contractant de ce dossier est introuvable ou anonymisé.');
        $contactId = $type === DocumentType::Convention ? $dossier['contact_person_id'] : $contractor['id'];
        $contact = $contactId === null ? null : ($this->fiches->personLabels([$contactId])[$contactId] ?? null);
        $email = $contactId === null ? null : ($this->fiches->personEmails([$contactId])[$contactId] ?? null);

        return [
            'name' => $identity['name'],
            'attention' => $type === DocumentType::Convention ? ($contact['label'] ?? null) : null,
            'address_line1' => $identity['address_line1'],
            'address_line2' => $identity['address_line2'],
            'postal_code' => $identity['postal_code'],
            'city' => $identity['city'],
            'email' => $email ?? ($type === DocumentType::Convention ? $identity['email'] : null),
            'person_id' => $contactId,
        ];
    }
}
