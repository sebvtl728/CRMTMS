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
 * The automatic certificates (2026-11-22, off by default): on the last day
 * at 13:30 (runLastDay, the afternoon counted), else once every month
 * of a session is closed (the attendance is final), each trainee
 * who never had one gets the completion certificate and the certificate of
 * realisation (realised hours, never 0: a manager decides), issued without author then sent (without a
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
            // Those who already have both documents (cancelled ones included) cost nothing more.
            $done = Document::query()->whereIn('enrolment_id', Paperwork::sessionEnrolments($sessionId))
                ->whereIn('type', array_map(fn (DocumentType $t) => $t->value, self::TYPES))
                ->toBase()->selectRaw('enrolment_id, count(distinct type) as n')->groupBy('enrolment_id')->pluck('n', 'enrolment_id');
            $enrolmentIds = array_values(array_filter(Paperwork::sessionEnrolments($sessionId), fn (string $id) => (int) ($done[$id] ?? 0) < count(self::TYPES)));
            $personIds = [];
            $realised = [];
            foreach ($enrolmentIds as $id) {
                $enrolment = Paperwork::enrolment($id);
                $personIds[$id] = (string) ($enrolment['person_id'] ?? '');
                $realised[$id] = (int) ($enrolment['realised_minutes'] ?? 0);
            }
            $people = $this->fiches->personLabels(array_values($personIds));
            $anonymised = $this->fiches->anonymisedIds(array_values($personIds));
            foreach ($enrolmentIds as $enrolmentId) {
                $person = $personIds[$enrolmentId];
                // In the trash, anonymised, or never attended (0 h realised: a manager decides): nothing.
                if (($people[$person]['trashed'] ?? true) || in_array($person, $anonymised, true) || $realised[$enrolmentId] === 0) {
                    continue;
                }
                foreach (self::TYPES as $type) {
                    $sent += $this->certify($enrolmentId, $type);
                }
            }
        }

        return $sent;
    }

    /**
     * The last day of a session, at 13:30 (tms:documents:last-day-certificates):
     * each trainee still there gets both documents, the séances of the day
     * not recorded yet counted as realised (the afternoon ahead). Left out:
     * a trainee who left, entered absent that day, in the trash or
     * anonymised. One who leaves in the afternoon: a manager cancels and
     * issues again. The closing run above catches up the others.
     */
    public function runLastDay(CarbonImmutable $now): int
    {
        if (! AutomationSettings::certificates()) {
            return 0;
        }
        $today = $now->setTimezone('Europe/Paris')->toDateString();
        $sent = 0;
        foreach (Paperwork::sessionsEndingWithSeanceOn($today) as $sessionId) {
            $candidates = [];
            foreach (Paperwork::sessionEnrolments($sessionId) as $enrolmentId) {
                $enrolment = Paperwork::enrolment($enrolmentId);
                if ($enrolment === null || $enrolment['status'] === 'abandoned' || $enrolment['left_on'] !== null || Paperwork::absentOn($enrolmentId, $today)) {
                    continue;
                }
                $candidates[$enrolmentId] = (string) $enrolment['person_id'];
            }
            $people = $this->fiches->personLabels(array_values($candidates));
            $anonymised = $this->fiches->anonymisedIds(array_values($candidates));
            foreach ($candidates as $enrolmentId => $person) {
                if (($people[$person]['trashed'] ?? true) || in_array($person, $anonymised, true)) {
                    continue;
                }
                foreach (self::TYPES as $type) {
                    $sent += $this->certify($enrolmentId, $type, $today);
                }
            }
        }

        return $sent;
    }

    private function certify(string $enrolmentId, DocumentType $type, ?string $completeOn = null): int
    {
        if (Document::query()->where('enrolment_id', $enrolmentId)->where('type', $type->value)->exists()) {
            return 0;
        }
        try {
            return DB::transaction(function () use ($enrolmentId, $type, $completeOn): int {
                if (! AutomationSends::claim(AutomationSends::CERTIFICATE, $enrolmentId, $type === DocumentType::CompletionCertificate ? 'completion' : 'realisation')) {
                    return 0;
                }
                $document = $this->issuer->issueForEnrolment(null, $type, $enrolmentId, $completeOn);
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
