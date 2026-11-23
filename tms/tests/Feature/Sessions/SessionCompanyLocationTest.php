<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Sessions\Public\Paperwork;
use App\Modules\Sessions\Sessions\SessionPlace;
use App\Modules\Sessions\Sessions\SessionWriter;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
});

function capCompany(): Company
{
    return Company::query()->create(['name' => 'CAP FORMATION', 'vat_regime' => 'exempt',
        'address_line1' => '12 rue des Lilas', 'address_postal_code' => '74000', 'address_city' => 'Annecy']);
}

it('holds a session in the premises of one of our companies', function () {
    $company = capCompany();

    $session = l2Session(null, ['location_kind' => 'company', 'location_company_id' => $company->id, 'location_text' => 'Salle 2']);

    expect($session->location_company_id)->toBe($company->id)
        ->and(SessionPlace::of($session))->toBe('CAP FORMATION, 12 rue des Lilas, 74000 Annecy (Salle 2)');
});

it('asks which company when the session is in our premises', function () {
    try {
        l2Session(null, ['location_kind' => 'company']);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['location_company_id'][0])->toBe('Choisissez la société.');
    }
});

it('forgets the company when the session moves elsewhere', function () {
    $session = l2Session(null, ['location_kind' => 'company', 'location_company_id' => capCompany()->id]);

    $moved = app(SessionWriter::class)->update($session, [...$session->only(['training_id', 'organisation_id', 'modality_id', 'capacity', 'responsible_user_id', 'notes']),
        'kind' => 'inter', 'status' => 'planned', 'planned_hours' => '', 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09',
        'location_kind' => 'remote', 'location_company_id' => $session->location_company_id, 'location_text' => null]);

    expect($moved->location_company_id)->toBeNull();
});

it('prints the company address on the documents', function () {
    $dossier = l2Dossier();
    $session = l2Session(null, ['location_kind' => 'company', 'location_company_id' => capCompany()->id]);
    l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));

    expect(Paperwork::dossier($dossier->id)['sessions'][0]['place'])->toBe('CAP FORMATION, 12 rue des Lilas, 74000 Annecy');
});

it('offers our companies as places on the quote assistant and the session form', function () {
    capCompany();

    $this->actingAs(admin(twoFactor: true))->get('/sessions/create')->assertInertia(fn ($p) => $p
        ->where('options.location', fn ($o) => collect($o)->contains('value', 'company'))
        ->where('options.location_companies', fn ($o) => collect($o)->contains('label', 'CAP FORMATION')));
});
