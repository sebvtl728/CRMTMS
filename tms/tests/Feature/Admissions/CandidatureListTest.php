<?php

use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Companies\Company;
use App\Modules\Sessions\Dossiers\Dossier;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/population.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
});

it('sorts the candidatures in tabs', function () {
    $p = adPopulation();

    expect(adListed([]))->toEqualCanonicalizing([$p['candidate']->id, $p['pending']->id])
        ->and(adListed(['onglet' => 'stagiaires']))->toBe([$p['apprenant']->id])
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
        ->where('counts', ['candidats' => 2, 'stagiaires' => 1, 'alternants' => 1, 'tous' => 5]));

    $this->get('/admissions/candidatures?onglet=alternants')->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.kind', 'Alternant')->where('rows.data.0.contract_type', 'Contrat d\'apprentissage'));
});

it('exports the list as a French CSV without any sensitive column', function () {
    adPopulation();
    $response = $this->actingAs(admin(twoFactor: true))->get('/admissions/candidatures/export?onglet=tous')->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and(explode("\r\n", substr($csv, 3))[0])->toBe('Numéro;Nom;Prénom;Étape;Parcours;Formation;"Code RNCP";DAS;Responsable;"Type de contrat";Employeur;"Début contrat";"Fin contrat";Dossier')
        ->and(substr_count($csv, "\r\n"))->toBe(6)
        ->and($csv)->not->toContain('RQTH')->not->toContain('adaptation');

    $this->actingAs(adUser([Permissions::CANDIDATURES_VIEW => null]))->get('/admissions/candidatures/export')->assertForbidden();
});
