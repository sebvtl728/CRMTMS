<?php

use App\Modules\Catalogue\Brochures;
use App\Modules\Catalogue\Brochures\Brochure;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/brochures.php';

beforeEach(function () {
    tenant();
    fakeImportDisk();
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('deposits a positioning test in the library, typed, and lists the types apart', function () {
    $this->actingAs(admin(twoFactor: true));
    $haccp = brochureTraining('HACCP');
    $test = depositBrochure('Test cuisine', ['kind' => 'positioning_test', 'das_ids' => [$haccp->das_id]]);
    $plaquette = depositBrochure('Plaquette cuisine', ['das_ids' => [$haccp->das_id]]);

    expect($test->kind)->toBe(Brochure::POSITIONING_TEST)->and($plaquette->kind)->toBe(Brochure::BROCHURE);

    $this->get('/catalogue/brochures?type=positioning_test')->assertInertia(fn (Assert $p) => $p
        ->has('brochures.data', 1)->where('brochures.data.0.title', 'Test cuisine')->where('brochures.data.0.kind_label', 'Test de positionnement')
        ->where('filters.type', 'positioning_test'));
    $this->get('/catalogue/brochures')->assertInertia(fn (Assert $p) => $p->has('brochures.data', 2));
    $this->get("/catalogue/brochures/{$test->id}/edit")->assertInertia(fn (Assert $p) => $p->where('brochure.kind', 'positioning_test'));

    $this->post('/catalogue/brochures', ['title' => 'X', 'file' => brochureFile(), 'kind' => 'autre'])->assertSessionHasErrors(['kind' => 'Type inconnu.']);
});

it('never offers a positioning test as a brochure, and offers the tests of a training or a das', function () {
    $this->actingAs(admin(twoFactor: true));
    $haccp = brochureTraining('HACCP');
    $incendie = brochureTraining('Incendie', 'Métiers supports');
    $dasTest = depositBrochure('Test du DAS', ['kind' => 'positioning_test', 'das_ids' => [$haccp->das_id]]);
    $trainingTest = depositBrochure('Test HACCP', ['kind' => 'positioning_test', 'training_ids' => [$haccp->id]]);
    $otherTest = depositBrochure('Test incendie', ['kind' => 'positioning_test', 'training_ids' => [$incendie->id]]);
    $plaquette = depositBrochure('Plaquette HACCP', ['training_ids' => [$haccp->id]]);
    $trashed = depositBrochure('Test corbeille', ['kind' => 'positioning_test', 'das_ids' => [$haccp->das_id]]);
    $this->delete("/catalogue/brochures/{$trashed->id}");

    $service = app(Brochures::class);
    expect(collect($service->forTrainings([$haccp->id]))->pluck('brochure_id')->all())->toBe([$plaquette->id])
        ->and(collect($service->options(null))->pluck('title')->all())->toBe(['Plaquette HACCP'])
        ->and(collect($service->forTrainingPage($haccp))->pluck('id')->all())->toBe([$plaquette->id])
        ->and($service->offered([$dasTest->current_version_id]))->toBe([]);

    // The tests of the training and of its DAS; with no training, those of the DAS given.
    expect(collect($service->positioningTests($haccp->id, null))->pluck('title')->all())->toBe(['Test du DAS', 'Test HACCP'])
        ->and(collect($service->positioningTests(null, $haccp->das_id))->pluck('title')->all())->toBe(['Test du DAS'])
        ->and(collect($service->positioningTests($incendie->id, $haccp->das_id))->pluck('title')->all())->toBe(['Test du DAS', 'Test incendie'])
        ->and($service->positioningTests(null, null))->toBe([])
        ->and($service->positioningTests($haccp->id, null)[0])->toHaveKeys(['brochure_id', 'version_id', 'title', 'original_name', 'size'])
        ->and($service->positioningVersions([$dasTest->current_version_id, $plaquette->current_version_id]))->toHaveCount(1);
    expect($otherTest)->not->toBeNull();
});
