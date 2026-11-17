<?php

use App\Modules\Catalogue\Brochures\BrochureStore;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Permissions;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingBlocks;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    tenant();
    fakeImportDisk();
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function showTraining(): Training
{
    return Training::query()->create([
        'code' => 'HACCP-3', 'title' => 'HACCP 3 jours', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'duration_hours' => '21.00', 'price_company' => '1250.50',
    ]);
}

it('renders the training with its brochures for catalogue.view', function () {
    $training = showTraining();
    app(BrochureStore::class)->create('Plaquette HACCP', UploadedFile::fake()->createWithContent('haccp.pdf', "%PDF-1.4\n%%EOF\n"), [$training->id], []);

    $this->actingAs(userWithPermissions([Permissions::VIEW]))->get("/catalogue/{$training->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalogue/show')
            ->where('training.title', 'HACCP 3 jours')
            ->where('training.code', 'HACCP-3')
            ->where('training.das', 'Hôtellerie-restauration')
            ->where('training.price_company', '1 250,50')
            ->where('brochures.0.title', 'Plaquette HACCP')
            ->where('can.manage', false));
});

it('shows « Modifier » only to who manages the catalogue', function () {
    $training = showTraining();

    $this->actingAs(userWithPermissions([Permissions::VIEW, Permissions::MANAGE]))->get("/catalogue/{$training->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', true));
});

it('refuses the page without catalogue.view and answers 404 for an unknown training', function () {
    $training = showTraining();

    $this->actingAs(userWithPermissions([]))->get("/catalogue/{$training->id}")->assertForbidden();
    $this->actingAs(userWithPermissions([Permissions::VIEW]))->get('/catalogue/'.Str::uuid7())->assertNotFound();
});

it('does not swallow the fixed catalogue routes', function () {
    $this->actingAs(userWithPermissions([Permissions::VIEW, Permissions::MANAGE]));

    $this->get('/catalogue/create')->assertInertia(fn (Assert $page) => $page->component('catalogue/form'));
    $this->get('/catalogue/brochures')->assertOk();
});

it('adds the blocks other modules register, skipping the ones returning null', function () {
    $training = showTraining();
    TrainingBlocks::register('demo', fn (string $id, $user) => ['title' => 'Démo', 'data' => ['id' => $id]]);
    TrainingBlocks::register('hidden', fn () => null);

    $this->actingAs(userWithPermissions([Permissions::VIEW]))->get("/catalogue/{$training->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('blocks', fn ($blocks) => collect($blocks)->pluck('key')->contains('demo') && ! collect($blocks)->pluck('key')->contains('hidden')));
});
