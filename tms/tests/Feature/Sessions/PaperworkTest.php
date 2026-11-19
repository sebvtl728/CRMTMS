<?php

use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Public\Paperwork;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('gives a dossier with its sessions, trainees and fundings for the documents', function () {
    $contact = makePerson('Martin', ['first_name' => 'Claire']);
    $dossier = l2Dossier(null, null, ['contact_person_id' => $contact->id]);
    $session = l2Session(null, ['planned_hours' => '21', 'location_kind' => 'address', 'location_text' => '12 rue des Lilas, Nantes']);
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']), ['planned_hours' => '14']);
    $tom = l2Enrol($dossier, $session, makePerson('Petit', ['first_name' => 'Tom']));
    app(Enrolments::class)->cancel($tom);
    l2Funding($dossier, ['agreement_number' => 'ACC-42']);

    $data = Paperwork::dossier($dossier->id);

    expect($data['number'])->toBe($dossier->number)
        ->and($data['contractor']['type'])->toBe('organisation')
        ->and($data['contact_person_id'])->toBe($contact->id)
        ->and($data['price_cents'])->toBe(100000)
        ->and($data['lines'][0])->toMatchArray(['label' => 'HACCP 3 jours', 'total_cents' => 100000])
        ->and($data['sessions'])->toHaveCount(1)
        ->and($data['sessions'][0])->toMatchArray(['id' => $session->id, 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09',
            'planned_minutes' => 1260, 'place' => '12 rue des Lilas, Nantes', 'training_id' => $session->training_id])
        ->and($data['trainees'])->toHaveCount(1)
        ->and($data['trainees'][0])->toMatchArray(['enrolment_id' => $lea->id, 'session_id' => $session->id, 'planned_minutes' => 840, 'entered_on' => '2026-12-07'])
        ->and($data['fundings'][0])->toMatchArray(['agreement_number' => 'ACC-42', 'amount_cents' => 60000]);
    expect(Paperwork::dossier('not-a-uuid'))->toBeNull();
});

it('moves the dossier to convention sent then signed, never backwards', function () {
    $dossier = l2Dossier();
    $paperwork = app(Paperwork::class);

    $paperwork->markConventionSent($dossier->id, CarbonImmutable::parse('2026-11-19'));
    expect($dossier->fresh()->status)->toBe(DossierStatus::ConventionSent);

    $paperwork->markConventionSigned($dossier->id, CarbonImmutable::parse('2026-11-20'));
    expect($dossier->fresh()->status)->toBe(DossierStatus::ConventionSigned)
        ->and($dossier->fresh()->convention_signed_on->toDateString())->toBe('2026-11-20');

    // A second sending (a new convention after a cancellation) leaves the state as it is.
    $paperwork->markConventionSent($dossier->id, CarbonImmutable::parse('2026-11-21'));
    expect($dossier->fresh()->status)->toBe(DossierStatus::ConventionSigned);

    // Signed straight from « Créé » (a contract signed before being sent by e-mail): both steps.
    $other = l2Dossier(makePerson('Roux', ['first_name' => 'Ana', 'is_individual' => true]));
    $paperwork->markConventionSigned($other->id, CarbonImmutable::parse('2026-11-19'));
    expect($other->fresh()->status)->toBe(DossierStatus::ConventionSigned);
});

it('gives an enrolment with its session, séances and realised hours for the documents', function () {
    $dossier = l2Dossier();
    $session = l2Session(null, ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-04', 'planned_hours' => '7', 'location_kind' => 'address', 'location_text' => 'Salle B, Nantes']);
    $module = plModule($session);
    plSeance($module, '2026-11-02', '09:00', '12:30');
    plSeance($module, '2026-11-03', '13:30', '17:00', ['place_text' => 'Atelier']);
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    $tom = l2Enrol($dossier, $session, makePerson('Petit', ['first_name' => 'Tom']));
    app(Enrolments::class)->cancel($tom);

    $data = Paperwork::enrolment($lea->id);

    expect($data)->toMatchArray(['id' => $lea->id, 'dossier_id' => $dossier->id, 'person_id' => $lea->person_id, 'entered_on' => '2026-11-02', 'left_on' => null, 'planned_minutes' => 420])
        ->and($data['realised_minutes'])->toBeInt()
        ->and($data['session'])->toMatchArray(['id' => $session->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-04', 'place' => 'Salle B, Nantes', 'cancelled' => false])
        ->and($data['schedule'])->toBe([
            ['date' => '2026-11-02', 'starts_at' => '09:00', 'ends_at' => '12:30', 'place' => null],
            ['date' => '2026-11-03', 'starts_at' => '13:30', 'ends_at' => '17:00', 'place' => 'Atelier'],
        ]);
    expect(Paperwork::sessionEnrolments($session->id))->toBe([$lea->id])
        ->and(Paperwork::enrolment('nope'))->toBeNull();
});
