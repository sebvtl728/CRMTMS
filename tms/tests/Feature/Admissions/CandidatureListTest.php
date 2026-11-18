<?php

use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureListQuery;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\DossierCreation;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Dossiers\Dossier;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
});

/**
 * Four candidatures: a candidate, a rejected one, an apprenant and an
 * alternant; plus a candidate with a contract and no dossier yet.
 *
 * @return array<string, Candidature>
 */
function adPopulation(): array
{
    $move = fn (Candidature $c, CandidatureStatus $to, ?string $reason = null) => app(CandidatureTransitions::class)->move($c, $to, admin(), $reason);
    $contract = function (Candidature $c): void {
        app(AlternanceWriter::class)->save($c->fresh(), ['contract_type' => 'apprenticeship',
            'employer_organisation_id' => Organisation::query()->firstOrCreate(['name' => 'Hôtel du Parc'])->id,
            'starts_on' => '2027-01-04', 'ends_on' => '2028-06-30'], admin());
    };
    $dossier = function (Candidature $c): void {
        $creation = app(DossierCreation::class);
        $creation->create($c->fresh(), $creation->defaults($c->fresh()), admin());
    };

    $candidate = adCandidature(['person_id' => adPerson('Alpha')->id]);
    $rejected = adCandidature(['person_id' => adPerson('Bravo')->id]);
    $move($rejected, CandidatureStatus::Rejected, crmItem(ReferenceList::CandidatureRefusalReason, 'Autre')->id);
    $apprenant = adCandidature(['person_id' => adPerson('Charlie', ['is_individual' => true])->id]);
    $move($apprenant, CandidatureStatus::Admitted);
    $dossier($apprenant);
    $alternant = adCandidature(['person_id' => adPerson('Delta')->id]);
    $move($alternant, CandidatureStatus::Interview);
    $contract($alternant);
    $move($alternant->fresh(), CandidatureStatus::Admitted);
    $dossier($alternant);
    $pending = adCandidature(['person_id' => adPerson('Echo')->id]);
    $move($pending, CandidatureStatus::Interview);
    $contract($pending);

    return compact('candidate', 'rejected', 'apprenant', 'alternant', 'pending');
}

function adListed(array $filters, ?User $user = null): array
{
    return CandidatureListQuery::build($user ?? admin(), CandidatureListQuery::filters($filters))->pluck('candidatures.id')->all();
}

it('sorts the candidatures in tabs', function () {
    $p = adPopulation();

    expect(adListed([]))->toEqualCanonicalizing([$p['candidate']->id, $p['pending']->id])
        ->and(adListed(['onglet' => 'apprenants']))->toBe([$p['apprenant']->id])
        ->and(adListed(['onglet' => 'alternants']))->toBe([$p['alternant']->id])
        ->and(adListed(['onglet' => 'tous']))->toHaveCount(5)
        ->and(adListed(['etape' => 'rejected']))->toBe([$p['rejected']->id]);

    // The dossier of the apprenant in the trash: back among the candidates.
    Dossier::query()->findOrFail($p['apprenant']->fresh()->dossier_id)->delete();
    expect(adListed([]))->toContain($p['apprenant']->id);
});

it('filters by training, contract and search, within the scope', function () {
    $p = adPopulation();
    $other = adCandidature(['person_id' => adPerson('Zoulou')->id, 'training_id' => adTraining('BP Arts de la cuisine', 'RNCP12345')->id]);

    expect(adListed(['formation' => $other->training_id]))->toBe([$other->id])
        ->and(adListed(['onglet' => 'tous', 'contrat' => 'apprenticeship']))->toEqualCanonicalizing([$p['alternant']->id, $p['pending']->id])
        ->and(adListed(['q' => 'zoulou']))->toBe([$other->id])
        ->and(adListed(['q' => $other->number]))->toBe([$other->id]);

    $own = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Own]);
    expect(adListed(['onglet' => 'tous'], $own))->toBe([]);
});

it('renders the list page with the tabs, its rows and the contract badge', function () {
    $p = adPopulation();

    $this->actingAs(commercial())->get('/admissions/candidatures')->assertInertia(fn (Assert $page) => $page
        ->component('admissions/candidatures/index')
        ->has('rows.data', 2)
        ->where('rows.data', fn ($rows) => collect($rows)->firstWhere('id', $p['pending']->id)['has_contract'] === true
            && collect($rows)->firstWhere('id', $p['pending']->id)['person'] === 'Léa Echo')
        ->where('counts', ['candidats' => 2, 'apprenants' => 1, 'alternants' => 1, 'tous' => 5]));

    $this->get('/admissions/candidatures?onglet=alternants')->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.kind', 'Alternant')->where('rows.data.0.contract_type', 'Contrat d\'apprentissage'));
});

it('exports the list as a French CSV without any sensitive column', function () {
    adPopulation();
    $response = $this->actingAs(admin(twoFactor: true))->get('/admissions/candidatures/export?onglet=tous')->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and(explode("\r\n", substr($csv, 3))[0])->toBe('Numéro;Nom;Prénom;Étape;Formation;"Code RNCP";DAS;Responsable;"Type de contrat";Employeur;"Début contrat";"Fin contrat";Dossier')
        ->and(substr_count($csv, "\r\n"))->toBe(6)
        ->and($csv)->not->toContain('RQTH')->not->toContain('adaptation');

    $this->actingAs(adUser([Permissions::CANDIDATURES_VIEW => null]))->get('/admissions/candidatures/export')->assertForbidden();
});
