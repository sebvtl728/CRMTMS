<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingData;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->actingAs(admin(twoFactor: true));
});

function rncpPayload(?string $rncp): array
{
    return [
        'title' => 'CAP Cuisine',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'rncp_code' => $rncp,
    ];
}

it('stores the RNCP code normalised', function () {
    $this->post('/catalogue/create', rncpPayload(' rncp 36004 '))->assertRedirect('/catalogue');

    $t = Training::query()->sole();
    expect($t->rncp_code)->toBe('RNCP36004')
        ->and(TrainingData::fromModel($t)->rncp_code)->toBe('RNCP36004');

    $this->get("/catalogue/{$t->id}/edit")->assertInertia(fn (Assert $p) => $p->where('training.rncp_code', 'RNCP36004'));
});

it('refuses a malformed RNCP code', function () {
    $this->post('/catalogue/create', rncpPayload('36004X'))
        ->assertSessionHasErrors(['rncp_code' => 'Le code RNCP doit s\'écrire RNCP suivi de chiffres (ex. RNCP36004).']);
    expect(Training::query()->count())->toBe(0);
});

it('stores an empty RNCP code as null', function () {
    $this->post('/catalogue/create', rncpPayload(''))->assertRedirect('/catalogue');

    expect(Training::query()->sole()->rncp_code)->toBeNull();
});
