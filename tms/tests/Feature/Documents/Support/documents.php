<?php

use App\Modules\Sessions\Dossiers\Dossier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
