<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function organisationType(string $code): ReferenceItem
{
    return ReferenceItem::query()->where('list', ReferenceList::OrganisationType)->where('code', $code)->firstOrFail();
}

it('flags « Entreprise cliente » as the role that shows the commercial status', function () {
    expect(organisationType('entreprise-cliente')->meta)->toBe(['client' => true])
        ->and(organisationType('opco')->meta)->toBe(['funder' => true])
        ->and(organisationType('sous-traitant')->meta)->toBeNull();
});

it('sends the client flag of each role to the entreprise form', function () {
    $this->actingAs(commercial())->get('/crm/organisations/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('options.types', fn ($types) => collect($types)->mapWithKeys(fn ($t) => [$t['label'] => $t['client']])->all() === [
            'Entreprise cliente' => true,
            'OPCO' => false,
            'Financeur public' => false,
            'Sous-traitant' => false,
            'Co-traitant' => false,
            'Prescripteur' => false,
        ]));
});

it('lets an administrator choose which roles show the status', function () {
    $this->actingAs(admin(twoFactor: true))->get('/admin/references')->assertInertia(fn (Assert $page) => $page
        ->where('lists.organisation_type.has_client', true)->where('lists.funding_type.has_client', false));

    $prescripteur = organisationType('prescripteur');
    $this->put("/admin/references/organisation_type/{$prescripteur->id}", ['label' => 'Prescripteur', 'meta' => ['client' => true]])->assertRedirect();
    expect($prescripteur->refresh()->meta)->toBe(['client' => true]);

    // The funder flag is kept when the form changes the client flag.
    $opco = organisationType('opco');
    $this->put("/admin/references/organisation_type/{$opco->id}", ['label' => 'OPCO', 'meta' => ['client' => false]])->assertRedirect();
    expect($opco->refresh()->meta)->toBe(['funder' => true]);
});

it('flags once, by migration, the « Entreprise cliente » role of each organisme', function () {
    $item = organisationType('entreprise-cliente');
    $item->update(['meta' => null]);
    $migration = require database_path('migrations/2027_01_30_000013_flag_client_organisation_type.php');

    $migration->up();
    $migration->up();
    expect($item->refresh()->meta)->toBe(['client' => true])
        ->and(organisationType('opco')->meta)->toBe(['funder' => true]);
});

it('shows the status on the fiche of a client or of an entreprise without role, not of an OPCO', function () {
    $this->actingAs(commercial());
    $client = Organisation::query()->create(['name' => 'Le Bistrot']);
    $client->types()->sync([organisationType('entreprise-cliente')->id => ['tenant_id' => tenant()->id], organisationType('co-traitant')->id => ['tenant_id' => tenant()->id]]);
    $opco = Organisation::query()->create(['name' => 'Akto']);
    $opco->types()->sync([organisationType('opco')->id => ['tenant_id' => tenant()->id]]);
    $bare = Organisation::query()->create(['name' => 'Sans rôle']);

    foreach ([[$client, true], [$opco, false], [$bare, true]] as [$organisation, $shows]) {
        $this->get("/crm/organisations/{$organisation->id}")->assertInertia(fn (Assert $page) => $page->where('organisation.shows_status', $shows));
    }
});
