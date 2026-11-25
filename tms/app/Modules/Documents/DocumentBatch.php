<?php

namespace App\Modules\Documents;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Billing;
use App\Modules\Sessions\Public\Paperwork;
use Illuminate\Support\Facades\DB;

/**
 * The per-trainee documents of a whole session (« Émettre pour tous »,
 * « Envoyer à tous »), limited to the trainees whose dossier the user may
 * manage. Issuing is all or nothing (one transaction); a trainee who
 * already has a valid document of the type is skipped.
 */
final class DocumentBatch
{
    public function __construct(private readonly DocumentIssuer $issuer, private readonly DocumentSender $sender) {}

    /** The number of documents issued. */
    public function issue(User $by, DocumentType $type, string $sessionId): int
    {
        return DB::transaction(function () use ($by, $type, $sessionId): int {
            $issued = 0;
            foreach ($this->enrolments($by, $sessionId) as $enrolmentId) {
                $living = Document::query()->where('enrolment_id', $enrolmentId)->where('type', $type->value)
                    ->where('status', '!=', DocumentStatus::Cancelled->value)->exists();
                if (! $living) {
                    $this->issuer->issueForEnrolment($by, $type, $enrolmentId);
                    $issued++;
                }
            }

            return $issued;
        });
    }

    /**
     * A trainee's form (individual contract, rules, image rights) for each
     * enrolled trainee of the dossier who has no valid one yet, all or
     * nothing. The number of documents issued (409 when none).
     */
    public function issueForDossier(User $by, DocumentType $type, string $dossierId): int
    {
        if (! in_array($type, DocumentType::fromDossier(), true)) {
            throw new \InvalidArgumentException("{$type->value} is not issued for the trainees of a dossier.");
        }

        return DB::transaction(function () use ($by, $type, $dossierId): int {
            Billing::lockDossier($dossierId);
            $dossier = Paperwork::dossier($dossierId) ?? throw new UserFacingHttpException(404, 'Dossier introuvable.');
            $enrolments = array_column(array_filter($dossier['trainees'], fn (array $t) => in_array($t['status'], ['enrolled', 'confirmed'], true)), 'enrolment_id');
            if ($enrolments === []) {
                throw new UserFacingHttpException(409, 'Ce dossier n\'a aucun stagiaire inscrit : aucun document ne peut être émis.');
            }
            $issued = 0;
            foreach ($enrolments as $enrolmentId) {
                $living = Document::query()->where('enrolment_id', $enrolmentId)->where('type', $type->value)
                    ->where('status', '!=', DocumentStatus::Cancelled->value)->exists();
                if (! $living) {
                    $this->issuer->issueForEnrolment($by, $type, $enrolmentId);
                    $issued++;
                }
            }
            if ($issued === 0) {
                throw new UserFacingHttpException(409, 'Chaque stagiaire du dossier a déjà ce document : annulez-le pour en émettre un nouveau.');
            }

            return $issued;
        });
    }

    /**
     * Sends every issued, never sent document of the type to its trainee
     * (the organisme's e-mail template); those without an e-mail are counted.
     *
     * @return array{queued: int, without_email: int}
     */
    public function send(User $by, DocumentType $type, string $sessionId): array
    {
        $queued = 0;
        $withoutEmail = 0;
        $documents = Document::query()->whereIn('enrolment_id', $this->enrolments($by, $sessionId))->where('type', $type->value)
            ->where('status', DocumentStatus::Issued->value)->where(fn ($q) => $q->whereNull('email_status')->orWhere('email_status', 'failed'))
            ->orderBy('number')->get();
        foreach ($documents as $document) {
            $defaults = DocumentMail::defaults($document);
            if ($defaults['to'] === '') {
                $withoutEmail++;

                continue;
            }
            $this->sender->send($by, $document, $defaults);
            $queued++;
        }

        return ['queued' => $queued, 'without_email' => $withoutEmail];
    }

    /**
     * The trainees of the session whose dossier $user may manage the documents of.
     *
     * @return list<string>
     */
    private function enrolments(User $user, string $sessionId): array
    {
        $allowed = [];

        return array_values(array_filter(Paperwork::sessionEnrolments($sessionId), function (string $enrolmentId) use ($user, &$allowed): bool {
            $dossierId = Paperwork::enrolment($enrolmentId)['dossier_id'] ?? null;

            return $dossierId !== null && ($allowed[$dossierId] ??= Paperwork::allows($user, Permissions::MANAGE, $dossierId));
        }));
    }
}
