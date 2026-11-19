<?php

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use App\Modules\Sessions\Dossiers\Dossier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/*
 * Helpers of the Documents tests (require_once: Pest loads only *Test.php).
 */
require_once __DIR__.'/../../Sessions/Support/lot2a.php';
require_once __DIR__.'/../../Sessions/Support/planning.php';

const DOC_PDF = "%PDF-1.7 document\n";

/** Gotenberg answers a recognisable PDF; the HTML it received is kept in Http's records. */
function docGotenberg(): void
{
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response(DOC_PDF, 200, ['Content-Type' => 'application/pdf'])]);
}

/** The HTML of the last document sent to Gotenberg. */
function docSentHtml(): string
{
    $html = '';
    Http::assertSent(function (Request $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $html = (string) $part['contents'];
            }
        }

        return true;
    });

    return $html;
}

/** A dossier of the « Hôtel du Parc » with Léa and Tom on a 21 h session in Nantes. */
function docCompanyDossier(): Dossier
{
    $contact = makePerson('Martin', ['first_name' => 'Claire', 'email' => 'claire.martin@hotel-parc.test']);
    $dossier = l2Dossier(null, null, ['contact_person_id' => $contact->id]);
    $session = l2Session(null, ['planned_hours' => '21', 'location_kind' => 'address', 'location_text' => '12 rue des Lilas, Nantes']);
    l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    l2Enrol($dossier, $session, makePerson('Petit', ['first_name' => 'Tom']));

    return $dossier->fresh();
}

/** A dossier of a particulier, Ana Roux, on the same kind of session. */
function docIndividualDossier(): Dossier
{
    $ana = makePerson('Roux', ['first_name' => 'Ana', 'is_individual' => true, 'email' => 'ana.roux@example.test']);
    $dossier = l2Dossier($ana);
    $session = l2Session(null, ['planned_hours' => '21', 'location_kind' => 'remote']);
    l2Enrol($dossier, $session, $ana);

    return $dossier->fresh();
}

/** The organisme's SMTP connector, its transport replaced: $send receives every message (and may throw). */
function docSmtp(Closure $send): ConnectorConfig
{
    $transport = new class($send) implements TransportInterface
    {
        public function __construct(private readonly Closure $send) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            ($this->send)($message);

            return null;
        }

        public function __toString(): string
        {
            return 'document://';
        }
    };
    app()->instance(SmtpTransportFactory::class, new class($transport) extends SmtpTransportFactory
    {
        public function __construct(private readonly TransportInterface $fake) {}

        public function make(array $settings, array $secrets): TransportInterface
        {
            return $this->fake;
        }
    });

    return ConnectorConfig::create([
        'connector_key' => 'smtp',
        'enabled' => true,
        'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'documents@cap.test', 'from_name' => 'CAP'],
        'secrets' => ['username' => 'cap-user', 'password' => 's3cret'],
    ]);
}

/**
 * A finished session (2–4 November 2026, two séances of 3 h 30) of the
 * « Hôtel du Parc » dossier with Léa (who has an e-mail) and Tom.
 *
 * @return array{dossier: App\Modules\Sessions\Dossiers\Dossier, session: App\Modules\Sessions\Sessions\TrainingSession, lea: App\Modules\Sessions\Enrolments\Enrolment, tom: App\Modules\Sessions\Enrolments\Enrolment}
 */
function docFinishedSession(): array
{
    $dossier = l2Dossier();
    $session = l2Session(null, ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-04', 'planned_hours' => '7', 'location_kind' => 'address', 'location_text' => 'Salle B, Nantes']);
    $module = plModule($session);
    plSeance($module, '2026-11-02', '09:00', '12:30');
    plSeance($module, '2026-11-03', '13:30', '17:00');
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea.durand@example.test']));
    $tom = l2Enrol($dossier, $session, makePerson('Petit', ['first_name' => 'Tom']));

    return ['dossier' => $dossier->fresh(), 'session' => $session, 'lea' => $lea, 'tom' => $tom];
}
