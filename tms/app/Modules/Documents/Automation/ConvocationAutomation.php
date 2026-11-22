<?php

namespace App\Modules\Documents\Automation;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Automation\AutomationSends;
use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentMail;
use App\Modules\Documents\DocumentSender;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The automatic convocations (2026-11-22): every morning, the confirmed
 * sessions whose first séance falls between today + 2 and today + N
 * (AutomationSettings::convocationDays, 0 = off) get the convocation of
 * each trainee who never had one (one cancelled by a manager is never
 * issued again), issued without author then sent (a trainee without e-mail:
 * issued only). A late confirmed session is convened the next morning; the
 * day before the start, nothing more. Each trainee is claimed once.
 */
final class ConvocationAutomation
{
    public function __construct(private readonly DocumentIssuer $issuer, private readonly DocumentSender $sender) {}

    /** The convocations queued by e-mail. */
    public function run(CarbonImmutable $today): int
    {
        $days = AutomationSettings::convocationDays();
        if ($days < 2) {
            return 0;
        }
        $today = CarbonImmutable::parse($today->setTimezone('Europe/Paris')->toDateString(), 'Europe/Paris');
        $sent = 0;
        foreach (Paperwork::confirmedSessionsStartingBetween($today->addDays(2)->toDateString(), $today->addDays($days)->toDateString()) as $session) {
            foreach (Paperwork::sessionEnrolments($session['session_id']) as $enrolmentId) {
                $sent += $this->convene($enrolmentId);
            }
        }

        return $sent;
    }

    /** 1 when the convocation was queued by e-mail. */
    private function convene(string $enrolmentId): int
    {
        if (Document::query()->where('enrolment_id', $enrolmentId)->where('type', DocumentType::Convocation->value)->exists()) {
            return 0;
        }
        try {
            return DB::transaction(function () use ($enrolmentId): int {
                if (! AutomationSends::claim(AutomationSends::CONVOCATION, $enrolmentId, '1')) {
                    return 0;
                }
                $document = $this->issuer->issueForEnrolment(null, DocumentType::Convocation, $enrolmentId);
                $message = DocumentMail::defaults($document);
                if ($message['to'] === '') {
                    return 0;
                }
                $this->sender->send(null, $document, $message);

                return 1;
            });
        } catch (UserFacingHttpException $e) {
            // Refused (no séance, PDF engine down…): nothing claimed, tried again tomorrow.
            Log::warning('Automatic convocation skipped.', ['enrolment' => $enrolmentId, 'status' => $e->getStatusCode()]);

            return 0;
        }
    }
}
