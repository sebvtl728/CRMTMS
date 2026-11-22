<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Export\OrganisationExport;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Public\Fiches;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('stores the IDCC of an entreprise, padded to 4 digits', function () {
    $this->actingAs(commercial())->post('/crm/organisations', ['name' => 'Brasserie', 'idcc' => '843'])->assertRedirect();

    expect(Organisation::query()->sole()->idcc)->toBe('0843');
});

it('accepts an entreprise without IDCC', function () {
    $this->actingAs(commercial())->post('/crm/organisations', ['name' => 'Brasserie', 'idcc' => ''])->assertRedirect();

    expect(Organisation::query()->sole()->idcc)->toBeNull();
});

it('rejects an IDCC that is not 1 to 4 digits', function (string $idcc) {
    $this->actingAs(commercial())->postJson('/crm/organisations', ['name' => 'X', 'idcc' => $idcc])
        ->assertStatus(422)->assertJsonPath('errors.idcc.0', 'L\'IDCC est un numéro de 4 chiffres (ex. 1979).');
})->with(['12345', 'HCR', '12a']);

it('shows the IDCC on the fiche', function () {
    $org = Organisation::query()->create(['name' => 'Brasserie', 'idcc' => '1979']);

    $this->actingAs(commercial())->get("/crm/organisations/{$org->id}")
        ->assertInertia(fn ($page) => $page->where('organisation.idcc', '1979'));
});

it('exports and imports the IDCC', function () {
    expect((new OrganisationExport)->columns())->toContain('IDCC')
        ->and(ImportTarget::Organisations->fields())->toHaveKey('idcc');
});

it('stores the collective agreements an OPCO covers, padded and without duplicates', function () {
    $this->actingAs(commercial())->post('/crm/organisations', ['name' => 'AKTO', 'covered_idcc' => '1979, 843 ;1979'])->assertRedirect();

    expect(Organisation::query()->sole()->covered_idcc)->toBe(['1979', '0843']);
    $this->postJson('/crm/organisations', ['name' => 'X', 'covered_idcc' => '1979, HCR'])
        ->assertStatus(422)->assertJsonPath('errors.covered_idcc.0', 'Indiquez des IDCC de 4 chiffres, séparés par des virgules (ex. 1979, 0843).');
});

it('finds the OPCO of an entreprise from its IDCC', function () {
    $opco = Organisation::query()->create(['name' => 'AKTO', 'covered_idcc' => ['1979', '0843']]);
    Organisation::query()->create(['name' => 'OPCO EP', 'covered_idcc' => ['1516']]);
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc', 'idcc' => '1979']);
    $other = Organisation::query()->create(['name' => 'Garage', 'idcc' => '1090']);

    expect(app(Fiches::class)->opcoFor($hotel->id))->toBe(['id' => $opco->id, 'label' => 'AKTO', 'idcc' => '1979'])
        ->and(app(Fiches::class)->opcoFor($other->id))->toBeNull();
});
