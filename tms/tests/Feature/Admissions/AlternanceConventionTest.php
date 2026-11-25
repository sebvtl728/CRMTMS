<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Sessions\Public\Paperwork;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create(['name' => 'CAP FORMATION']);
});

it('exposes the alternance contract of a dossier to the paperwork', function () {
    $c = bookletApprentice();

    $alternance = Paperwork::dossier($c->dossier_id)['alternance'];
    expect($alternance['contract_type'])->toBe('apprenticeship')
        ->and($alternance['starts_on'])->toBe('2027-01-04')
        ->and($alternance['ends_on'])->toBe('2027-03-31')
        ->and($alternance['apprentice_person_id'])->toBe($c->person_id)
        ->and($alternance['tutor_person_id'])->not->toBeNull()
        ->and($alternance['opco_organisation_id'])->not->toBeNull();
});
