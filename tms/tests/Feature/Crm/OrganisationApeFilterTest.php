<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationListQuery;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/** @param array<string, mixed> $attributes */
function apeOrg(string $name, ?string $naf, array $attributes = []): Organisation
{
    return Organisation::query()->create(['name' => $name, 'naf_code' => $naf, ...$attributes]);
}

it('normalises the APE filter: dots, spaces and case, junk ignored', function () {
    $filter = fn (mixed $v) => OrganisationListQuery::filters(['ape' => $v], commercial())['ape'];

    expect($filter('5610A'))->toBe('5610A')
        ->and($filter(' 56.10a '))->toBe('5610A')
        ->and($filter('56'))->toBe('56')
        ->and($filter('56.1'))->toBe('561')
        ->and($filter('restaurant'))->toBeNull()
        ->and($filter('5610AB'))->toBeNull()
        ->and($filter(['56']))->toBeNull()
        ->and($filter(null))->toBeNull();
});

it('filters the entreprises by exact APE code or by family prefix', function () {
    apeOrg('Restaurant traditionnel', '5610A');
    apeOrg('Cafétéria', '56.10B');
    apeOrg('Débit de boissons', '5630z');
    apeOrg('Hôtel', '5510Z');
    apeOrg('Sans code', null);

    $names = fn (string $query) => collect($this->actingAs(commercial())->get('/crm/organisations'.$query)->original->getData()['page']['props']['organisations']['data'])->pluck('name')->all();

    expect($names('?ape=5610A'))->toBe(['Restaurant traditionnel'])
        ->and($names('?ape=56.10a'))->toBe(['Restaurant traditionnel'])
        ->and($names('?ape=5610B'))->toBe(['Cafétéria'])
        ->and($names('?ape=56.10'))->toBe(['Cafétéria', 'Restaurant traditionnel'])
        ->and($names('?ape=56'))->toBe(['Cafétéria', 'Débit de boissons', 'Restaurant traditionnel'])
        ->and($names('?ape=5630Z'))->toBe(['Débit de boissons'])
        ->and($names('?ape=5'))->toBe(['Cafétéria', 'Débit de boissons', 'Hôtel', 'Restaurant traditionnel'])
        ->and($names('?ape=47'))->toBe([])
        ->and($names('?ape=n%27importe'))->toHaveCount(5);
});

it('combines the APE filter with the other filters and keeps it in the props', function () {
    $me = commercial();
    apeOrg('Restaurant client', '5610A', ['status' => 'client', 'city' => 'Lyon']);
    apeOrg('Restaurant prospect', '5610A', ['status' => 'prospect', 'responsible_user_id' => $me->id]);
    apeOrg('Hôtel client', '5510Z', ['status' => 'client']);

    $page = fn (string $query) => $this->actingAs($me)->get('/crm/organisations'.$query)->original->getData()['page']['props'];
    $names = fn (string $query) => collect($page($query)['organisations']['data'])->pluck('name')->all();

    expect($names('?ape=56&status=client'))->toBe(['Restaurant client'])
        ->and($names('?ape=56&mine=1'))->toBe(['Restaurant prospect'])
        ->and($names('?ape=56&q=lyon'))->toBe(['Restaurant client'])
        ->and($page('?ape=56.10a')['filters']['ape'])->toBe('5610A')
        ->and($page('?ape=56&page=1')['organisations']['links'][1]['url'] ?? '')->toContain('ape=56');
});

it('keeps the visibility scope under the APE filter', function () {
    $mine = ReferenceItem::query()->where('list', ReferenceList::Das)->where('label', 'Hôtellerie-restauration')->firstOrFail();
    $other = ReferenceItem::query()->where('list', ReferenceList::Das)->where('label', 'Métiers supports')->firstOrFail();
    apeOrg('Visible', '5610A')->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    apeOrg('Cachée', '5610A')->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);

    $user = userWithRole(Role::ResponsableDas);
    DB::table('user_das')->insert(['tenant_id' => tenant()->id, 'user_id' => $user->id, 'das_id' => $mine->id]);

    $names = collect($this->actingAs($user)->get('/crm/organisations?ape=5610A')->original->getData()['page']['props']['organisations']['data'])->pluck('name')->all();

    expect($names)->toBe(['Visible']);
});

it('applies the APE filter to the export and saved filters', function () {
    $this->actingAs(commercial());
    apeOrg('Restaurant', '5610A');
    apeOrg('Hôtel', '5510Z');

    $rows = exRows($this->get('/crm/organisations/export?columns[]=name&columns[]=naf_code&ape=56'));
    expect($rows)->toBe([['Nom', 'Code NAF'], ['Restaurant', '5610A']]);

    expect(OrganisationListQuery::SAVABLE)->toContain('ape')
        ->and(OrganisationListQuery::rules())->toHaveKey('ape');
});
