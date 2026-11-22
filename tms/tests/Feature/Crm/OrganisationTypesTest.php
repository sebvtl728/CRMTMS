<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Export\OrganisationExport;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function otType(string $label): ReferenceItem
{
    return ReferenceItem::query()->where('list', ReferenceList::OrganisationType)->where('label', $label)->firstOrFail();
}

it('seeds the organisation types of an organisme, OPCO and public funders marking funders', function () {
    expect(ReferenceItem::query()->where('list', ReferenceList::OrganisationType)->orderBy('position')->pluck('label')->all())
        ->toBe(['Entreprise cliente', 'OPCO', 'Financeur public', 'Sous-traitant', 'Co-traitant', 'Prescripteur'])
        ->and(otType('OPCO')->meta)->toBe(['funder' => true])
        ->and(otType('Financeur public')->meta)->toBe(['funder' => true])
        ->and(otType('Sous-traitant')->meta)->toBeNull();
});

it('stores the types of an entreprise, shows them and filters the list', function () {
    $opco = otType('OPCO');
    $this->actingAs(commercial())->post('/crm/organisations', ['name' => 'OPCO EP', 'type_ids' => [$opco->id]])->assertRedirect();
    Organisation::query()->create(['name' => 'Hôtel du Lac']);

    $org = Organisation::query()->where('name', 'OPCO EP')->sole();
    expect($org->types->pluck('id')->all())->toBe([$opco->id])
        ->and(DB::table('organisation_types')->where('organisation_id', $org->id)->value('tenant_id'))->toBe(tenant()->id);

    $this->get("/crm/organisations/{$org->id}")->assertInertia(fn (Assert $p) => $p->where('organisation.types.0.label', 'OPCO'));
    $this->get("/crm/organisations?type={$opco->id}")->assertInertia(fn (Assert $p) => $p
        ->where('organisations.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['OPCO EP']));
});

it('exports the types and keeps a used type from being deleted', function () {
    $org = Organisation::query()->create(['name' => 'OPCO EP']);
    $org->types()->sync([otType('OPCO')->id => ['tenant_id' => tenant()->id]]);

    expect((new OrganisationExport)->columns())->toHaveKey('types')
        ->and((new OrganisationExport)->value($org->fresh(), 'types'))->toBe('OPCO')
        ->and(ReferenceUsage::isInUse(otType('OPCO')))->toBeTrue()
        ->and(ReferenceUsage::isInUse(otType('Prescripteur')))->toBeFalse();
});

it('unites the types of two merged entreprises', function () {
    $keep = Organisation::query()->create(['name' => 'OPCO EP']);
    $drop = Organisation::query()->create(['name' => 'OPCO EP Lyon']);
    $keep->types()->sync([otType('OPCO')->id => ['tenant_id' => tenant()->id]]);
    $drop->types()->sync([otType('Financeur public')->id => ['tenant_id' => tenant()->id]]);

    $this->actingAs(admin(twoFactor: true))->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])->assertRedirect();

    expect($keep->fresh()->types->pluck('label')->sort()->values()->all())->toBe(['Financeur public', 'OPCO']);
});
