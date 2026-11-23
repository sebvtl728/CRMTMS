<?php

use App\Modules\Admissions\Booklet\BookletSettings;
use App\Modules\Core\Companies\Company;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Storage::fake('local');
    Company::factory()->default()->create(['name' => 'CAP FORMATION', 'phone' => '02 28 44 32 14']);
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $this->html = [];
    $this->merged = 0;
    Http::fake(['gotenberg:3000/*' => function (HttpRequest $request) {
        if (str_ends_with($request->url(), '/merge')) {
            $this->merged++;

            return Http::response('%PDF-1.7 livret complet', 200, ['Content-Type' => 'application/pdf']);
        }
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $this->html[] = $part['contents'];
            }
        }

        return Http::response('%PDF-1.7 partie', 200, ['Content-Type' => 'application/pdf']);
    }]);
});

it('prints the booklet already filled: partners, pathway, planning, monthly pages and grids', function () {
    BookletSettings::save(['team' => [['role' => 'Responsable de formation', 'name' => 'Franck Gardie-Rondeau', 'phone' => null, 'email' => null]], 'director_note' => 'Fabrice Robert, directeur général']);
    $c = bookletApprentice();

    $response = $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}/livret.pdf")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('livret-apprentissage-')
        ->and($this->merged)->toBe(0)
        ->and($this->html)->toHaveCount(1);
    $html = $this->html[0];
    $missing = array_values(array_filter([
        'Employé polyvalent en restauration', 'Léa', 'DURAND', '3 rue des Lilas 44000 Nantes', '06 11 22 33 44',
        'Marc', 'Père', 'Hôtel du Parc', 'Paul Martin', 'paul.martin@example.test', 'AKTO', 'CAP FORMATION',
        'Franck Gardie-Rondeau', 'Fabrice Robert, directeur général', 'Laurent Cauret', 'Pratique culinaire',
        'Mise en place', 'Dressage', 'Janvier 2027', 'Février 2027', 'Mars 2027', 'Travaux effectués en entreprise',
        'Travaux effectués en centre de formation', 'Réaliser la mise en place des entrées', 'Peser les produits',
        '04/01/2027', '31/03/2027',
    ], fn (string $text) => ! str_contains($html, e($text))));
    expect($missing)->toBe([]);
    // Centre pages only for the months with séances: January (8 h) and February (4 h), not March.
    expect(substr_count($html, 'Travaux effectués en centre de formation'))->toBe(2)
        ->and(substr_count($html, 'Travaux effectués en entreprise'))->toBe(3)
        ->and($html)->toContain('12 h');
});

it('merges the annexes of the organisme after the pathway and before the monthly pages', function () {
    $this->actingAs(admin(twoFactor: true));
    BookletSettings::addAnnex('Règlement intérieur', UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\nannexe"));
    $c = bookletApprentice();

    $this->get("/admissions/candidatures/{$c->id}/livret.pdf")->assertOk()->assertContent('%PDF-1.7 livret complet');
    expect($this->merged)->toBe(1)->and($this->html)->toHaveCount(2)
        ->and($this->html[0])->toContain('Planning')->and($this->html[0])->not->toContain('Travaux effectués')
        ->and($this->html[1])->toContain('Travaux effectués en entreprise');
});

it('needs a contract and the right to see the candidature', function () {
    $c = adCandidature();
    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}/livret.pdf")
        ->assertStatus(409);
    $this->actingAs(adUser(['crm.opportunities.view' => null]))->get("/admissions/candidatures/{$c->id}/livret.pdf")->assertNotFound();
});
