<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\OpportunityTrainings;
use App\Modules\Crm\Organisations\Organisation;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->actingAs(commercial());
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id;
    $this->haccp = Training::query()->create(['title' => 'HACCP 3 jours', 'das_id' => $das]);
    $this->haccp->syncFundingModes([crmItem(ReferenceList::FundingMode, 'OPCO')->id]);
    $this->free = Training::query()->create(['title' => 'Barista', 'das_id' => $das]);
});

it('warns when the funding mode of the opportunity is not offered by one of its trainings', function () {
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['funding_mode_id' => crmItem(ReferenceList::FundingMode, 'CPF')->id]);
    OpportunityTrainings::sync($opportunity, [['training_id' => $this->haccp->id, 'quantity' => '1'], ['training_id' => $this->free->id, 'quantity' => '1']]);

    $this->get("/crm/opportunities/{$opportunity->id}")->assertInertia(fn (Assert $p) => $p
        ->where('funding_warning', 'Mode de financement « CPF » non prévu au catalogue pour : HACCP 3 jours.'));

    $opportunity->update(['funding_mode_id' => crmItem(ReferenceList::FundingMode, 'OPCO')->id]);
    $this->get("/crm/opportunities/{$opportunity->id}")->assertInertia(fn (Assert $p) => $p->where('funding_warning', null));

    // No funding mode chosen, or a training that lists none: nothing to say.
    $opportunity->update(['funding_mode_id' => null]);
    $this->get("/crm/opportunities/{$opportunity->id}")->assertInertia(fn (Assert $p) => $p->where('funding_warning', null));
});

it('gives the form the funding modes of each training, for a live warning', function () {
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);

    $this->get("/crm/opportunities/create?organisation={$org->id}")->assertInertia(fn (Assert $p) => $p
        ->where('options.training', fn ($options) => collect($options)->firstWhere('value', $this->haccp->id)['funding_mode_ids'] === [crmItem(ReferenceList::FundingMode, 'OPCO')->id]
            && collect($options)->firstWhere('value', $this->free->id)['funding_mode_ids'] === []));
});
