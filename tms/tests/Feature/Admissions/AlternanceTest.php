<?php

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Alternance\ContractType;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

function adInterview(): Candidature
{
    $c = adCandidature();
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Interview, admin());

    return $c->fresh();
}

/** @return array<string, mixed> */
function adContract(array $overrides = []): array
{
    $employer = Organisation::query()->create(['name' => 'Hôtel du Parc']);

    return [
        'contract_type' => 'apprenticeship',
        'employer_organisation_id' => $employer->id,
        'tutor_person_id' => adPerson('Martin', ['first_name' => 'Paul', 'birth_date' => null])->id,
        'starts_on' => '2027-01-04',
        'ends_on' => '2028-06-30',
        'opco_organisation_id' => Organisation::query()->create(['name' => 'OPCO EP'])->id,
        ...$overrides,
    ];
}

it('records the contract from the interview on, once per candidature', function () {
    $c = adInterview();
    app(AlternanceWriter::class)->save($c, adContract(), admin());
    app(AlternanceWriter::class)->save($c->fresh(), ['contract_type' => 'professionalisation'] + adContract(), admin());

    $contract = AlternanceContract::query()->sole();
    expect($contract->contract_type)->toBe(ContractType::Professionalisation)
        ->and($contract->starts_on->toDateString())->toBe('2027-01-04');
});

it('refuses a contract before the interview', function () {
    try {
        app(AlternanceWriter::class)->save(adCandidature(), adContract(), admin());
        $this->fail('expected a 409');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409)->and($e->getMessage())->toBe('Le contrat se saisit à partir de l\'entretien.');
    }
});

it('checks the dates and the CRM fiches', function () {
    $c = adInterview();
    $errors = function (array $data) use ($c): array {
        try {
            app(AlternanceWriter::class)->save($c, $data, admin());
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };

    expect($errors(adContract(['ends_on' => '2026-12-31']))['ends_on'][0])->toBe('La fin du contrat doit suivre son début.')
        ->and($errors(adContract(['employer_organisation_id' => '0190a8c4-0000-7000-8000-000000000000'])))->toHaveKey('employer_organisation_id')
        ->and($errors(adContract(['contract_type' => 'cdi'])))->toHaveKey('contract_type');

    $trashed = Organisation::query()->create(['name' => 'Fermé']);
    $trashed->delete();
    expect($errors(adContract(['opco_organisation_id' => $trashed->id])))->toHaveKey('opco_organisation_id');
});

it('shows the contract on the candidature page and deletes it', function () {
    $c = adInterview();
    $this->actingAs(commercial())->put("/admissions/candidatures/{$c->id}/alternance", adContract())->assertRedirect();

    $this->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('candidature.alternance.contract_type', 'apprenticeship')
        ->where('candidature.alternance.contract_type_label', 'Contrat d\'apprentissage')
        ->where('candidature.alternance.employer.label', 'Hôtel du Parc')
        ->where('candidature.alternance.tutor.label', 'Paul Martin')
        ->where('candidature.alternance.opco.label', 'OPCO EP'));

    $this->delete("/admissions/candidatures/{$c->id}/alternance")->assertRedirect();
    expect(AlternanceContract::query()->count())->toBe(0);
});
