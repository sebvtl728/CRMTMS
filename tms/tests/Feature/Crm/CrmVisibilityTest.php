<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
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
        expect(Scopes::of($user, Permissions::FICHES_VIEW) === AccessScope::Das)->toBeFalse()
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

    expect(Scopes::of($user, Permissions::FICHES_VIEW) === AccessScope::Das)->toBeTrue()
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

/**
 * @return array{user: User, visible: list<string>}
 */
function peopleVisibilityScenario(): array
{
    $mine = das('Hôtellerie-restauration');
    $other = das('Métiers supports');
    $stamp = ['tenant_id' => tenant()->id];

    $inMine = Organisation::query()->create(['name' => 'Chez moi']);
    $inMine->das()->sync([$mine->id => $stamp]);
    $inOther = Organisation::query()->create(['name' => 'Chez eux']);
    $inOther->das()->sync([$other->id => $stamp]);
    $noDas = Organisation::query()->create(['name' => 'Sans DAS']);

    $person = fn (string $name, array $das = []) => tap(makePerson($name), fn ($p) => $p->das()->sync(collect($das)->mapWithKeys(fn ($d) => [$d->id => $stamp])->all()));

    affiliate($person('Contact chez moi'), $inMine);
    affiliate($person('Contact chez eux'), $inOther);
    affiliate($person('Contact sans DAS'), $noDas);
    // Active affiliation elsewhere: his own DAS on the person does not count.
    affiliate($person('Contact ailleurs mais DAS suivi', [$mine]), $inOther);
    // Former contact of a visible entreprise only: individual rule applies.
    affiliate($person('Ancien chez moi'), $inMine, ['ended_on' => '2026-01-01']);
    affiliate($person('Ancien chez moi avec DAS suivi', [$mine]), $inMine, ['ended_on' => '2026-01-01']);
    $person('Particulier DAS suivi', [$mine]);
    $person('Particulier DAS autre', [$other]);
    $person('Particulier deux DAS dont suivi', [$other, $mine]);
    $person('Particulier sans DAS');
    $removed = affiliate($person('Rattachement supprimé DAS suivi', [$mine]), $inOther);
    $removed->delete();
    affiliate($person('Deux rattachements', [$other]), $inOther);
    affiliate(Person::query()->where('last_name', 'Deux rattachements')->sole(), $inMine);

    $user = userWithRole(Role::ResponsableDas);
    followDas($user, $mine);

    return ['user' => $user, 'visible' => [
        'Ancien chez moi avec DAS suivi',
        'Contact chez moi',
        'Deux rattachements',
        'Particulier DAS suivi',
        'Particulier deux DAS dont suivi',
        'Rattachement supprimé DAS suivi',
    ]];
}

it('shows a Responsable DAS the people of his entreprises and the unaffiliated ones of his DAS', function () {
    ['user' => $user, 'visible' => $expected] = peopleVisibilityScenario();

    $names = CrmVisibility::people(Person::query(), $user)->orderBy('last_name')->pluck('last_name')->all();

    expect($names)->toBe($expected);
});

it('does not restrict people for anybody but the Responsable DAS', function () {
    makePerson('Libre');

    foreach ([Role::AdminOrganisme, Role::Direction, Role::Commercial, Role::ChargeRelations, Role::Assistant] as $role) {
        expect(CrmVisibility::people(Person::query(), userWithRole($role))->count())->toBe(1);
    }
});

it('shows nothing to a Responsable DAS who follows no DAS', function () {
    $org = Organisation::query()->create(['name' => 'Quelconque']);
    affiliate(makePerson('Contact'), $org);
    makePerson('Particulier');

    expect(CrmVisibility::people(Person::query(), userWithRole(Role::ResponsableDas))->count())->toBe(0);
});

it('does not open a person through a trashed entreprise, keeps the followed DAS on the person', function () {
    $mine = das('Hôtellerie-restauration');
    $stamp = ['tenant_id' => tenant()->id];
    $gone = Organisation::query()->create(['name' => 'Fermée']);
    $gone->das()->sync([$mine->id => $stamp]);
    $alive = Organisation::query()->create(['name' => 'Ouverte']);
    $alive->das()->sync([$mine->id => $stamp]);

    affiliate(makePerson('Chez la fermée'), $gone);
    affiliate(makePerson('Chez ouverte'), $alive);
    $followed = makePerson('Fermée mais DAS suivi');
    $followed->das()->sync([$mine->id => $stamp]);
    affiliate($followed, $gone);
    affiliate(makePerson('Ancien chez ouverte'), $alive, ['ended_on' => '2026-01-01']);
    $gone->delete();

    $user = userWithRole(Role::ResponsableDas);
    followDas($user, $mine);

    expect(CrmVisibility::people(Person::query(), $user)->orderBy('last_name')->pluck('last_name')->all())
        ->toBe(['Chez ouverte', 'Fermée mais DAS suivi']);
});
