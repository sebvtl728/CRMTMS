<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('answers 404 for a training of another organisme', function () {
    $other = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($other);
    $theirs = app(TenantContext::class)->run($other, fn () => Training::query()->create([
        'code' => 'EUX', 'title' => 'Chez eux', 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id,
    ]));
    inTenant();

    $this->actingAs(admin(twoFactor: true));
    $this->get("/catalogue/{$theirs->id}/edit")->assertNotFound();
    $this->put("/catalogue/{$theirs->id}/edit", ['title' => 'Piratée'])->assertNotFound();
    $this->post("/catalogue/{$theirs->id}/toggle")->assertNotFound();
    $this->delete("/catalogue/{$theirs->id}")->assertNotFound();

    expect(collect($this->get('/catalogue')->original->getData()['page']['props']['trainings']['data']))->toBeEmpty()
        ->and(app(Trainings::class)->find($theirs->id))->toBeNull()
        ->and(app(Trainings::class)->options())->toBe([]);

    $still = app(TenantContext::class)->run($other, fn () => Training::query()->sole());
    expect($still->title)->toBe('Chez eux')
        ->and($still->is_active)->toBeTrue()
        ->and($still->trashed())->toBeFalse();
});

it('answers 404 for a malformed id or a deleted training', function () {
    $training = Training::query()->create(['title' => 'Supprimée', 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);
    $training->delete();

    $this->actingAs(admin(twoFactor: true));
    $this->get('/catalogue/pas-un-uuid/edit')->assertNotFound();
    $this->get("/catalogue/{$training->id}/edit")->assertNotFound();
    $this->post("/catalogue/{$training->id}/toggle")->assertNotFound();
});

it('refuses a das of another organisme', function () {
    $other = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($other);
    $theirDas = app(TenantContext::class)->run($other, fn () => crmItem(ReferenceList::Das, 'Métiers supports'));
    inTenant();

    $this->actingAs(admin(twoFactor: true))->post('/catalogue/create', ['title' => 'Test', 'das_id' => $theirDas->id])
        ->assertSessionHasErrors('das_id');
    expect(Training::query()->count())->toBe(0);
});

it('keeps guests out', function () {
    $this->get('/catalogue')->assertRedirect('/login');
});
