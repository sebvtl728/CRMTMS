<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'Europe/Paris'));
});

it('exports the cards of the tab as a French CSV', function () {
    $user = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    makeOpportunity($hotel, ['amount' => '1470', 'expected_close_on' => '2026-11-28']);

    $csv = $this->actingAs($user)->get('/crm/dashboards/export.csv?tab=month')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();

    $lines = explode("\r\n", trim(substr($csv, 3)));
    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($lines[0])->toBe('Indicateur;Formule;Période;Valeur;Période précédente;Objectif')
        ->and(collect($lines)->first(fn ($l) => str_starts_with($l, 'CA prévisionnel;')))
        ->toBe('CA prévisionnel;Somme des montants des opportunités ouvertes à clôturer dans la période;du 01/11/2026 au 30/11/2026;1 470,00 €;0,00 €;');
    expect(AuditLog::query()->where('event', 'crm.dashboard.exported')->exists())->toBeTrue();
});

it('exports the page as a PDF rendered by Gotenberg, with its filters', function () {
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response("%PDF-1.7 tableaux\n", 200, ['Content-Type' => 'application/pdf'])]);

    $this->actingAs(commercial())->get('/crm/dashboards/export.pdf?tab=week&target=person')->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    Http::assertSent(function (Request $r) {
        $html = collect($r->data())->firstWhere('filename', 'index.html')['contents'] ?? '';

        return str_contains($html, 'Tableaux de bord') && str_contains($html, 'du 16/11/2026 au 22/11/2026')
            && str_contains($html, 'Particuliers') && str_contains($html, 'Appels faits');
    });
});

it('refuses the exports without crm.export', function () {
    $user = userWithPermissions(['crm.opportunities.view']);
    $this->actingAs($user)->get('/crm/dashboards/export.csv')->assertForbidden();
    $this->actingAs($user)->get('/crm/dashboards/export.pdf')->assertForbidden();
});
