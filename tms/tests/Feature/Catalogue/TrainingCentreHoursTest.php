<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingExport;
use App\Modules\Catalogue\TrainingModules;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('stores the hours in the centre and in the company, shown on the form, the page and the export', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/catalogue/create', [
        'title' => 'Commis de cuisine', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'duration_hours' => '1 316', 'centre_hours' => '994', 'company_hours' => '322,5',
    ])->assertRedirect('/catalogue');

    $training = Training::query()->sole();
    expect($training->centre_hours)->toBe('994.00')->and($training->company_hours)->toBe('322.50');

    $this->get("/catalogue/{$training->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->where('training.centre_hours', '994.00')->where('training.company_hours', '322.50'));
    $this->get("/catalogue/{$training->id}")->assertInertia(fn (Assert $p) => $p
        ->where('training.centre_hours', '994')->where('training.company_hours', '322,5'));
    expect((new TrainingExport)->columns())->toMatchArray(['centre_hours' => 'Heures en centre', 'company_hours' => 'Heures en entreprise']);

    $this->post('/catalogue/create', ['title' => 'X', 'das_id' => $training->das_id, 'centre_hours' => '-3'])
        ->assertSessionHasErrors('centre_hours');
});

it('compares the modules with the centre hours when they are given', function () {
    $this->actingAs(admin(twoFactor: true));
    $training = Training::query()->create(['title' => 'Commis', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'duration_hours' => '100', 'centre_hours' => '70', 'company_hours' => '30']);
    TrainingModules::sync($training, [['title' => 'A', 'duration_hours' => '35'], ['title' => 'B', 'duration_hours' => '35']]);

    $this->get("/catalogue/{$training->id}/edit")->assertInertia(fn (Assert $p) => $p->where('training.modules_gap', false));
    $training->update(['centre_hours' => null]);
    $this->get("/catalogue/{$training->id}/edit")->assertInertia(fn (Assert $p) => $p->where('training.modules_gap', true));
});
