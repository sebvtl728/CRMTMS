<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => CapFormationReferenceSeeder::run(tenant()));

function das(string $label): ReferenceItem
{
    return ReferenceItem::query()->where('list', ReferenceList::Das)->where('label', $label)->firstOrFail();
}

it('does not restrict anybody but the Responsable DAS', function () {
    Organisation::query()->create(['name' => 'Libre']);

    foreach ([Role::AdminOrganisme, Role::Direction, Role::Commercial, Role::ChargeRelations, Role::Assistant] as $role) {
        $user = userWithRole($role);
        expect(CrmVisibility::restricts($user))->toBeFalse()
            ->and(CrmVisibility::organisations(Organisation::query(), $user)->count())->toBe(1);
    }
});

it('limits the Responsable DAS to the organisations of his followed DAS', function () {
    $mine = das('Hôtellerie-restauration');
    $both = Organisation::query()->create(['name' => 'Deux DAS']);
    $both->das()->sync([$mine->id => ['tenant_id' => tenant()->id], das('Métiers supports')->id => ['tenant_id' => tenant()->id]]);
    $none = Organisation::query()->create(['name' => 'Aucun DAS']);
    $user = userWithRole(Role::ResponsableDas);
    DB::table('user_das')->insert(['tenant_id' => tenant()->id, 'user_id' => $user->id, 'das_id' => $mine->id]);

    $ids = CrmVisibility::organisations(Organisation::query(), $user)->pluck('id')->all();

    expect(CrmVisibility::restricts($user))->toBeTrue()
        ->and($ids)->toBe([$both->id])
        ->and($ids)->not->toContain($none->id)
        ->and(CrmVisibility::followedDas($user))->toBe([$mine->id]);
});

it('builds the people filter on active affiliations of visible organisations', function () {
    $user = userWithRole(Role::ResponsableDas);
    $sql = CrmVisibility::people(Organisation::query(), $user)->toSql();

    expect($sql)->toContain('"affiliations"')->toContain('"user_das"')
        ->and(CrmVisibility::people(Organisation::query(), userWithRole(Role::Commercial))->toSql())->not->toContain('affiliations');
});
