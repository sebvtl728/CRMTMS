<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\OfficialOpcos;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Public\Fiches;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('knows the OPCO of an IDCC from the official table', function () {
    expect(OfficialOpcos::of('1979'))->toBe('akto')
        ->and(OfficialOpcos::of('1486'))->toBe('atlas')
        ->and(OfficialOpcos::of('0016'))->toBe('mobilites')
        ->and(OfficialOpcos::of('9999'))->toBeNull()
        ->and(OfficialOpcos::label('opco_ep'))->toBe('OPCO EP');
});

it('lists the eleven OPCO with their official IDCC', function () {
    $options = OfficialOpcos::options();

    expect($options)->toHaveCount(11)
        ->and(collect($options)->firstWhere('key', 'akto')['idcc'])->toContain('1979', '1501');
});

it('finds the OPCO fiche from the official table when no fiche covers the IDCC', function () {
    $akto = Organisation::query()->create(['name' => 'Akto']);
    Organisation::query()->create(['name' => 'Atlas Électricité']);
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Lac', 'idcc' => '1979']);

    expect(app(Fiches::class)->opcoFor($hotel->id))->toBe(['id' => $akto->id, 'label' => 'Akto', 'idcc' => '1979', 'official' => 'AKTO']);
});

it('prefers the covered conventions entered on a fiche over the official table', function () {
    Organisation::query()->create(['name' => 'AKTO']);
    $other = Organisation::query()->create(['name' => 'OPCO régional', 'covered_idcc' => ['1979']]);
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Lac', 'idcc' => '1979']);

    expect(app(Fiches::class)->opcoFor($hotel->id))->toBe(['id' => $other->id, 'label' => 'OPCO régional', 'idcc' => '1979', 'official' => null]);
});

it('names the official OPCO even without a fiche for it', function () {
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Lac', 'idcc' => '1979']);

    expect(app(Fiches::class)->opcoFor($hotel->id))->toBe(['id' => null, 'label' => 'AKTO', 'idcc' => '1979', 'official' => 'AKTO']);
});

it('does not take a namesake entreprise for an OPCO', function () {
    Organisation::query()->create(['name' => 'Atlas Électricité']);
    $bureau = Organisation::query()->create(['name' => 'Cabinet', 'idcc' => '1486']);

    expect(app(Fiches::class)->opcoFor($bureau->id)['id'])->toBeNull();
});

it('gives the official OPCO list to the entreprise form', function () {
    $this->actingAs(admin(twoFactor: true))->get('/crm/organisations/create')->assertInertia(fn ($p) => $p
        ->has('official_opcos', 11)
        ->where('official_opcos.0.label', 'AFDAS'));
});

it('saves the official IDCC list of the largest OPCO taken on the form', function () {
    $idcc = collect(OfficialOpcos::options())->firstWhere('key', 'ocapiat')['idcc'];

    $this->actingAs(commercial())->post('/crm/organisations', ['name' => 'OCAPIAT', 'covered_idcc' => implode(', ', $idcc)])->assertRedirect();

    expect(Organisation::query()->sole()->covered_idcc)->toBe($idcc);
});
