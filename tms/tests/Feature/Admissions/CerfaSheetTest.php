<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Storage::fake('local');
    Company::factory()->default()->create(['name' => 'CAP FORMATION', 'siret' => '49358822200080', 'nda_number' => '52440512344']);
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $this->html = null;
    Http::fake(['gotenberg:3000/*' => function (HttpRequest $request) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $this->html = $part['contents'];
            }
        }

        return Http::response('%PDF-1.7 cerfa', 200, ['Content-Type' => 'application/pdf']);
    }]);
});

it('fills the Cerfa sheet in the order of the form, the unknown fields marked to complete', function () {
    $c = bookletApprentice();
    Organisation::query()->where('name', 'Hôtel du Parc')->update(['siret' => '73282932000074', 'naf_code' => '5510Z', 'idcc' => '1979']);
    $c->forceFill(['rqth' => true, 'last_diploma' => 'CAP Cuisine'])->save();

    $response = $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}/cerfa.pdf")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('fiche-cerfa-apprentissage-');
    $html = (string) $this->html;
    $expected = [
        'Employeur', 'Hôtel du Parc', '732 829 320 00074', '5510Z', '1979', 'AKTO',
        'Apprenti', 'DURAND', 'Léa', '12/05/2004', '3 rue des Lilas 44000 Nantes', 'Marc', 'Père', 'CAP Cuisine',
        "Maître d'apprentissage", 'Paul Martin', 'paul.martin@example.test',
        'Contrat', '04/01/2027', '31/03/2027',
        'Formation', 'Employé polyvalent en restauration', 'RNCP36004', 'CAP FORMATION', '493 588 222 00080', '52440512344',
        'NIR', 'à compléter', 'Reconnaissance travailleur handicapé', 'Oui',
    ];
    expect(array_values(array_filter($expected, fn (string $t) => ! str_contains($html, e($t)))))->toBe([]);
});

it('leaves out the RQTH for a user without the right to sensitive data', function () {
    $c = bookletApprentice();
    $c->forceFill(['rqth' => true])->save();

    $this->actingAs(adUser(['admissions.candidatures.view' => null]))->get("/admissions/candidatures/{$c->id}/cerfa.pdf")->assertOk();

    expect((string) $this->html)->toContain('Reconnaissance travailleur handicapé')->toContain('réservé aux personnes autorisées')
        ->and((string) $this->html)->not->toContain('>Oui<');
});

it('needs a contract', function () {
    $c = adCandidature();
    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}/cerfa.pdf")->assertStatus(409);
});
