<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Search\SavedFilter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/** @param array<string, mixed> $attributes */
function sfFilter(string $name, array $attributes = []): SavedFilter
{
    return SavedFilter::query()->create(['resource' => 'organisations', 'name' => $name, 'filters' => ['status' => 'client'], ...$attributes]);
}

it('creates a saved filter for the signed-in user', function () {
    $user = commercial();
    $this->actingAs($user);

    $this->postJson('/crm/saved-filters', [
        'resource' => 'organisations', 'name' => 'Clients lyonnais', 'is_shared' => false,
        'filters' => ['status' => 'client', 'q' => 'lyon', 'mine' => true, 'sort' => 'city', 'dir' => 'desc'],
        'columns' => ['name', 'city', 'siret'],
    ])->assertCreated()->assertJsonPath('data.name', 'Clients lyonnais');

    $filter = SavedFilter::query()->sole();
    expect($filter->user_id)->toBe($user->id)
        ->and($filter->tenant_id)->toBe(tenant()->id)
        ->and($filter->filters)->toEqual(['status' => 'client', 'q' => 'lyon', 'mine' => true, 'sort' => 'city', 'dir' => 'desc'])
        ->and($filter->columns)->toBe(['name', 'city', 'siret'])
        ->and($filter->is_shared)->toBeFalse();
});

it('rejects keys, values and columns outside the allowed ones', function (array $payload, string $field) {
    $this->actingAs(commercial());

    $this->postJson('/crm/saved-filters', ['resource' => 'organisations', 'name' => 'X', 'filters' => [], ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(SavedFilter::query()->count())->toBe(0);
})->with([
    'unknown filter key' => [['filters' => ['password' => 'x']], 'filters'],
    'people key on organisations' => [['filters' => ['situation' => '019b0000-0000-7000-8000-000000000000']], 'filters'],
    'bad enum' => [['filters' => ['status' => 'zombie']], 'filters.status'],
    'bad uuid' => [['filters' => ['das' => 'not-a-uuid']], 'filters.das'],
    'nested array' => [['filters' => ['q' => ['a' => 'b']]], 'filters.q'],
    'bad sort' => [['filters' => ['sort' => 'password; drop']], 'filters.sort'],
    'unknown column' => [['columns' => ['name', 'password']], 'columns.1'],
    'trashed is not savable' => [['filters' => ['trashed' => true]], 'filters'],
    'unknown resource' => [['resource' => 'users'], 'resource'],
    'empty name' => [['name' => ' '], 'name'],
    'too long name' => [['name' => str_repeat('a', 101)], 'name'],
]);

it('validates the people keys on a people filter', function () {
    $this->actingAs(commercial());

    $this->postJson('/crm/saved-filters', ['resource' => 'people', 'name' => 'Particuliers', 'filters' => ['type' => 'individual'], 'columns' => ['last_name', 'mobile']])->assertCreated();
    $this->postJson('/crm/saved-filters', ['resource' => 'people', 'name' => 'Mauvais', 'filters' => ['sector' => '019b0000-0000-7000-8000-000000000000']])->assertUnprocessable();
    $this->postJson('/crm/saved-filters', ['resource' => 'people', 'name' => 'Mauvais', 'filters' => [], 'columns' => ['siret']])->assertUnprocessable();
});

it('shows a shared filter to every user of the organisme and a private one only to its author', function () {
    $author = commercial();
    $this->actingAs($author);
    $shared = sfFilter('Partagé', ['is_shared' => true]);
    $private = sfFilter('Privé');
    $peopleFilter = sfFilter('Autre ressource', ['resource' => 'people', 'filters' => []]);

    $this->actingAs(assistant());
    $names = collect($this->getJson('/crm/saved-filters?resource=organisations')->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Partagé'])
        ->and($this->getJson('/crm/saved-filters?resource=organisations')->json('data.0'))->toMatchArray(['mine' => false, 'is_shared' => true, 'author' => $author->name]);
    $this->putJson('/crm/saved-filters/'.$private->id, ['name' => 'Piraté'])->assertNotFound();

    $this->actingAs($author);
    $own = collect($this->getJson('/crm/saved-filters?resource=organisations')->json('data'))->pluck('name')->sort()->values()->all();
    expect($own)->toBe(['Partagé', 'Privé']);
});

it('lets only the author edit or delete a shared filter', function () {
    $author = commercial();
    $this->actingAs($author);
    $shared = sfFilter('Partagé', ['is_shared' => true]);

    $this->actingAs(userWithRole(Role::ChargeRelations));
    $this->putJson('/crm/saved-filters/'.$shared->id, ['name' => 'Volé'])->assertForbidden();
    $this->deleteJson('/crm/saved-filters/'.$shared->id)->assertForbidden();
    expect($shared->fresh()->name)->toBe('Partagé');

    $this->actingAs($author);
    $this->putJson('/crm/saved-filters/'.$shared->id, ['name' => 'Renommé', 'is_shared' => false, 'filters' => ['status' => 'prospect'], 'columns' => ['name']])
        ->assertOk();
    $shared->refresh();
    expect($shared->name)->toBe('Renommé')->and($shared->is_shared)->toBeFalse()->and($shared->filters)->toBe(['status' => 'prospect'])->and($shared->columns)->toBe(['name']);

    $this->deleteJson('/crm/saved-filters/'.$shared->id)->assertNoContent();
    expect(SavedFilter::query()->count())->toBe(0);
});

it('keeps the resource of a saved filter fixed and validates updates against it', function () {
    $this->actingAs(commercial());
    $filter = sfFilter('Entreprises');

    $this->putJson('/crm/saved-filters/'.$filter->id, ['resource' => 'people', 'filters' => ['type' => 'individual']])->assertUnprocessable();
    expect($filter->fresh()->resource)->toBe('organisations');
});

it('does not see the saved filters of another tenant', function () {
    $this->actingAs(commercial());
    $mine = sfFilter('Mon filtre', ['is_shared' => true]);

    $other = tenantOnHost('autre.test');
    $foreignUser = userIn($other);
    $foreign = app(TenantContext::class)->run($other, fn () => SavedFilter::query()->create([
        'resource' => 'organisations', 'name' => 'Étranger', 'filters' => [], 'is_shared' => true, 'user_id' => $foreignUser->id,
    ]));

    $names = collect($this->getJson('/crm/saved-filters?resource=organisations')->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Mon filtre']);
    $this->deleteJson('/crm/saved-filters/'.$foreign->id)->assertNotFound();
});

it('is audited without keeping search terms', function () {
    $this->actingAs(commercial());

    $this->postJson('/crm/saved-filters', ['resource' => 'people', 'name' => 'Recherche', 'filters' => ['q' => 'Dupont Jean']])->assertCreated();

    $log = AuditLog::query()->where('event', 'created')->where('auditable_type', 'saved_filter')->sole();
    expect(json_encode($log->new_values, JSON_UNESCAPED_UNICODE))->not->toContain('Dupont');
});

it('needs crm.view', function () {
    $this->actingAs(userWithRole(Role::Assistant));
    $this->postJson('/crm/saved-filters', ['resource' => 'organisations', 'name' => 'ok', 'filters' => []])->assertCreated();

    $this->getJson('/crm/saved-filters?resource=organisations')->assertOk();
    auth()->logout();
    $this->getJson('/crm/saved-filters?resource=organisations')->assertUnauthorized();
});

it('passes the saved filters and export rights to the list pages', function () {
    $user = commercial();
    $this->actingAs($user);
    sfFilter('Clients', ['is_shared' => true]);

    $this->get('/crm/organisations')->assertInertia(fn (Assert $page) => $page
        ->component('crm/organisations/index')
        ->has('savedFilters', 1)
        ->where('savedFilters.0.name', 'Clients')
        ->where('can.export', true)
        ->has('columns.options')
        ->has('columns.defaults'));
    $this->get('/crm/people')->assertInertia(fn (Assert $page) => $page->has('savedFilters', 0)->where('can.export', true));

    $this->actingAs(assistant());
    $this->get('/crm/organisations')->assertInertia(fn (Assert $page) => $page->where('can.export', false));
});

it('tells the list page which saved filter is active and exposes every exportable cell', function () {
    $this->actingAs(commercial());
    $filter = sfFilter('Clients', ['is_shared' => true, 'columns' => ['name', 'city']]);
    Organisation::query()->create(['name' => 'Alpha', 'city' => 'Lyon']);

    $this->get('/crm/organisations?saved='.$filter->id)->assertInertia(fn (Assert $page) => $page
        ->where('activeSavedId', $filter->id)
        ->where('savedFilters.0.columns', ['name', 'city'])
        ->where('organisations.data.0.cells.name', 'Alpha')
        ->where('organisations.data.0.cells.city', 'Lyon')
        ->has('organisations.data.0.cells.status'));
    $this->get('/crm/organisations?saved=nope')->assertInertia(fn (Assert $page) => $page->where('activeSavedId', null));
    $this->get('/crm/organisations?saved[]=x')->assertOk();
    $this->get('/crm/people?saved='.$filter->id)->assertInertia(fn (Assert $page) => $page->where('activeSavedId', null));
});

it('does not expose another user private filter as active', function () {
    $this->actingAs(commercial());
    $private = sfFilter('Privé');

    $this->actingAs(assistant());
    $this->get('/crm/organisations?saved='.$private->id)->assertInertia(fn (Assert $page) => $page->where('activeSavedId', null));
});

it('does not fail on an array resource and caps long search terms', function () {
    $this->actingAs(commercial());

    $this->getJson('/crm/saved-filters?resource[]=x')->assertOk();
    $this->get('/crm/organisations?q='.str_repeat('a', 300))->assertInertia(fn (Assert $page) => $page->where('filters.q', str_repeat('a', 100)));
});
