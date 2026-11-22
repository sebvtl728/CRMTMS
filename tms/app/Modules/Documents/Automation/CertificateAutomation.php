<?php

namespace App\Modules\Documents\Automation;

use App\Modules\Core\Automation\AutomationSends;
use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentMail;
use App\Modules\Documents\DocumentSender;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The automatic certificates (2026-11-22, off by default): once the month of
 * the last day of a session is closed (the attendance is final), each trainee
 * who never had one gets the completion certificate and the certificate of
 * realisation (realised hours), issued without author then sent (without a
 * valid address: issued only). A certificate cancelled by a manager is never
 * issued again; one closed month is caught up within CATCH_UP_DAYS.
 */
final class CertificateAutomation
{
    public const CATCH_UP_DAYS = 30;

    public const TYPES = [DocumentType::CompletionCertificate, DocumentType::RealisationCertificate];

    public function __construct(private readonly DocumentIssuer $issuer, private readonly DocumentSender $sender, private readonly Fiches $fiches) {}

    /** The certificates queued by e-mail. */
    public function run(CarbonImmutable $today): int
    {
        if (! AutomationSettings::certificates()) {
            return 0;
        }
        $sent = 0;
        foreach (Paperwork::sessionsFinallyClosedSince($today->subDays(self::CATCH_UP_DAYS)) as $sessionId) {
            $enrolmentIds = Paperwork::sessionEnrolments($sessionId);
            $personIds = [];
            foreach ($enrolmentIds as $id) {
                $personIds[$id] = (string) (Paperwork::enrolment($id)['person_id'] ?? '');
            }
            $people = $this->fiches->personLabels(array_values($personIds));
            $anonymised = $this->fiches->anonymisedIds(array_values($personIds));
            foreach ($enrolmentIds as $enrolmentId) {
                $person = $personIds[$enrolmentId];
                if (($people[$person]['trashed'] ?? true) || in_array($person, $anonymised, true)) {
                    continue;
                }
                foreach (self::TYPES as $type) {
                    $sent += $this->certify($enrolmentId, $type);
                }
            }
        }

        return $sent;
    }

    private function certify(string $enrolmentId, DocumentType $type): int
    {
        if (Document::query()->where('enrolment_id', $enrolmentId)->where('type', $type->value)->exists()) {
            return 0;
        }
        try {
            return DB::transaction(function () use ($enrolmentId, $type): int {
                if (! AutomationSends::claim(AutomationSends::CERTIFICATE, $enrolmentId, $type === DocumentType::CompletionCertificate ? 'completion' : 'realisation')) {
                    return 0;
                }
                $document = $this->issuer->issueForEnrolment(null, $type, $enrolmentId);
                $message = DocumentMail::defaults($document);
                if ($message['to'] === '' || filter_var($message['to'], FILTER_VALIDATE_EMAIL) === false) {
                    return 0;
                }
                $this->sender->send(null, $document, $message);

                return 1;
            });
        } catch (Throwable $e) {
            Log::warning('Automatic certificate skipped.', ['enrolment' => $enrolmentId, 'type' => $type->value, 'error' => $e::class]);

            return 0;
        }
    }
}
