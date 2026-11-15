<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\OpportunitiesProvider;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Visibility\CrmVisibility;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('das visibility on opportunities', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $visible = makeOpportunity($organisation, ['title' => 'Visible', 'das_id' => $mine->id]);
    $hidden = makeOpportunity($organisation, ['title' => 'Cachée', 'das_id' => $other->id]);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);

    expect(CrmVisibility::opportunities(Opportunity::query(), $responsable)->pluck('id')->all())->toBe([$visible->id])
        ->and(CrmVisibility::opportunities(Opportunity::query(), commercial())->count())->toBe(2);

    $this->actingAs($responsable);
    $titles = collect($this->get('/crm/opportunities')->assertOk()->original->getData()['page']['props']['opportunities']['data'])->pluck('title')->all();
    expect($titles)->toBe(['Visible']);

    $this->get("/crm/opportunities/{$visible->id}")->assertOk();
    $this->get("/crm/opportunities/{$hidden->id}")->assertNotFound();
    // Filtering on a DAS he does not follow shows nothing more.
    $titles = collect($this->get('/crm/opportunities?das='.$other->id)->original->getData()['page']['props']['opportunities']['data'])->pluck('title')->all();
    expect($titles)->toBe([]);

    // The tab of the fiche follows the same rule.
    $organisation->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $this->get("/crm/organisations/{$organisation->id}")
        ->assertInertia(fn ($page) => $page->has('opportunities', 1)->where('opportunities.0.title', 'Visible'));
});

it('answers 404 for a malformed id or an opportunity of a trashed fiche', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($organisation);
    $organisation->delete();

    $this->actingAs(assistant())->get('/crm/opportunities/pas-un-uuid')->assertNotFound();
    $this->get("/crm/opportunities/{$opportunity->id}")->assertNotFound();
    expect(collect($this->get('/crm/opportunities')->original->getData()['page']['props']['opportunities']['data']))->toBeEmpty();

    // With crm.fiches.delete / crm.opportunities.delete the fiche (and its opportunity) stays readable.
    $this->actingAs(commercial())->get("/crm/opportunities/{$opportunity->id}")->assertOk();
});

it('refuses to create on a fiche the user cannot see', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $hidden = Organisation::query()->create(['name' => 'Ailleurs']);
    $trashed = Organisation::query()->create(['name' => 'Supprimée']);
    $trashed->delete();

    $this->actingAs(commercial())->postJson('/crm/opportunities', [
        'organisation_id' => $trashed->id, 'title' => 'X', 'das_id' => $mine->id, 'stage_id' => pipelineStage('Nouveau')->id,
    ])->assertJsonValidationErrors('organisation_id');
    $this->get("/crm/opportunities/create?organisation={$trashed->id}")->assertNotFound();

    // A Responsable DAS granted crm.opportunities.update only creates in the DAS he follows, on fiches he sees.
    $responsable = userWithRole(Role::ResponsableDas);
    $responsable->givePermissionTo('crm.opportunities.update');
    followDas($responsable, $mine);
    $this->actingAs($responsable)->postJson('/crm/opportunities', [
        'organisation_id' => $hidden->id, 'title' => 'X', 'das_id' => $mine->id, 'stage_id' => pipelineStage('Nouveau')->id,
    ])->assertJsonValidationErrors('organisation_id');
    expect(Opportunity::count())->toBe(0);
});

it('refuses to edit an opportunity of a trashed fiche', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($organisation);
    $organisation->delete();

    // crm.opportunities.delete reads it, but nothing is edited on a trashed fiche.
    $this->actingAs(commercial())->get("/crm/opportunities/{$opportunity->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.update', false)->where('can.delete', false));
    $this->get("/crm/opportunities/{$opportunity->id}/edit")->assertForbidden();
    $this->put("/crm/opportunities/{$opportunity->id}", ['title' => 'X', 'das_id' => $opportunity->das_id])->assertForbidden();
    $this->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Contacté')->id])->assertForbidden();
    $this->delete("/crm/opportunities/{$opportunity->id}")->assertForbidden();
    expect($opportunity->refresh()->stage_id)->toBe(pipelineStage('Nouveau')->id);
});

it('the opportunity history provider fails closed without a signed-in user', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    makeOpportunity($org, ['title' => 'HACCP']);
    expect(app(OpportunitiesProvider::class)->entriesFor($org, null, 30))->toHaveCount(1);
    auth()->logout();

    expect(app(OpportunitiesProvider::class)->entriesFor($org, null, 30))->toHaveCount(0);
});
