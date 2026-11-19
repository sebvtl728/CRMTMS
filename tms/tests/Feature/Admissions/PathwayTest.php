<?php

use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\CandidatureWriter;
use App\Modules\Admissions\Candidatures\Pathway;
use App\Modules\Core\Companies\Company;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/population.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
});

function pwInterview(array $data = []): Candidature
{
    $c = adCandidature($data);
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Interview, admin());

    return $c->fresh();
}

/** @return array<string, mixed> */
function pwContract(): array
{
    return [
        'contract_type' => 'apprenticeship',
        'employer_organisation_id' => Organisation::query()->firstOrCreate(['name' => 'Hôtel du Parc'])->id,
        'starts_on' => '2027-01-04', 'ends_on' => '2028-06-30',
    ];
}

it('records the pathway of a candidature, undecided by default', function () {
    $c = adCandidature();
    expect($c->pathway)->toBeNull();

    $c = app(CandidatureWriter::class)->update($c, ['pathway' => 'trainee'], admin());
    expect($c->pathway)->toBe(Pathway::Trainee)->and($c->pathway->label())->toBe('Stagiaire');

    $c = app(CandidatureWriter::class)->update($c, ['pathway' => ''], admin());
    expect($c->pathway)->toBeNull();

    try {
        app(CandidatureWriter::class)->update($c, ['pathway' => 'stage'], admin());
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['pathway'][0])->toBe('Parcours inconnu.');
    }
});

it('sets the pathway to alternance with the contract, and keeps it while the contract exists', function () {
    $c = pwInterview();
    app(AlternanceWriter::class)->save($c, pwContract(), admin());
    expect($c->fresh()->pathway)->toBe(Pathway::Alternance);

    try {
        app(CandidatureWriter::class)->update($c->fresh(), ['pathway' => 'trainee'], admin());
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['pathway'][0])->toBe('Retirez d\'abord le contrat d\'alternance.');
    }

    app(AlternanceWriter::class)->delete($c->fresh());
    expect(app(CandidatureWriter::class)->update($c->fresh(), ['pathway' => 'trainee'], admin())->pathway)->toBe(Pathway::Trainee);
});

it('refuses an alternance contract to a trainee', function () {
    $c = pwInterview(['pathway' => 'trainee']);

    try {
        app(AlternanceWriter::class)->save($c, pwContract(), admin());
        $this->fail('expected a 409');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409)
            ->and($e->getMessage())->toBe('Ce candidat suit le parcours « Stagiaire » : changez son parcours pour saisir un contrat.');
    }
});

it('badges a trainee with a dossier « Stagiaire », lists and exports the pathway', function () {
    $p = adPopulation();
    app(CandidatureWriter::class)->update($p['candidate']->fresh(), ['pathway' => 'trainee'], admin());

    expect(adListed(['onglet' => 'stagiaires']))->toBe([$p['apprenant']->id])
        ->and(adListed(['onglet' => 'tous', 'parcours' => 'trainee']))->toBe([$p['candidate']->id])
        ->and(adListed(['onglet' => 'tous', 'parcours' => 'alternance']))->toEqualCanonicalizing([$p['alternant']->id, $p['pending']->id])
        ->and(adListed(['onglet' => 'tous', 'parcours' => 'a_definir']))->toEqualCanonicalizing([$p['apprenant']->id, $p['rejected']->id]);

    $this->actingAs(commercial())->get('/admissions/candidatures?onglet=stagiaires')->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.kind', 'Stagiaire')
        ->where('counts.stagiaires', 1));
    $this->get('/admissions/candidatures/'.$p['candidate']->id)->assertInertia(fn (Assert $page) => $page
        ->where('candidature.pathway', 'trainee')
        ->where('candidature.pathway_label', 'Stagiaire')
        ->where('options.pathways', [['value' => 'alternance', 'label' => 'Alternance'], ['value' => 'trainee', 'label' => 'Stagiaire']]));

    $csv = $this->actingAs(admin(twoFactor: true))->get('/admissions/candidatures/export?onglet=tous')->assertOk()->streamedContent();
    expect($csv)->toContain('Parcours')->toContain('Stagiaire');
});
