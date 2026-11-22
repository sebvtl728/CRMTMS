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
 * The automatic convocations (2026-11-22): every morning, the confirmed
 * sessions whose first séance falls between today + 2 and today + N
 * (AutomationSettings::convocationDays, 0 = off) get the convocation of
 * each trainee who never had one (enrolled or confirmed, not left, out of the trash, not anonymised) (one cancelled by a manager is never
 * issued again), issued without author then sent (a trainee without e-mail:
 * issued only). A late confirmed session is convened the next morning; the
 * day before the start, nothing more. Each trainee is claimed once.
 */
final class ConvocationAutomation
{
    public function __construct(private readonly DocumentIssuer $issuer, private readonly DocumentSender $sender, private readonly Fiches $fiches) {}

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
            $trainees = Paperwork::convocable($session['session_id'], $session['starts_on']);
            $people = $this->fiches->personLabels(array_column($trainees, 'person_id'));
            $anonymised = $this->fiches->anonymisedIds(array_column($trainees, 'person_id'));
            foreach ($trainees as $trainee) {
                // A person in the trash or anonymised is never convened.
                if (($people[$trainee['person_id']]['trashed'] ?? true) || in_array($trainee['person_id'], $anonymised, true)) {
                    continue;
                }
                $sent += $this->convene($trainee['id']);
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
                // Without a valid address: issued, not sent (« À traiter » shows it to a manager).
                if ($message['to'] === '' || filter_var($message['to'], FILTER_VALIDATE_EMAIL) === false) {
                    return 0;
                }
                $this->sender->send(null, $document, $message);

                return 1;
            });
        } catch (Throwable $e) {
            // Refused (no séance, PDF engine down…): nothing claimed, tried again tomorrow; the others go on.
            Log::warning('Automatic convocation skipped.', ['enrolment' => $enrolmentId, 'error' => $e::class]);

            return 0;
        }
    }
}
