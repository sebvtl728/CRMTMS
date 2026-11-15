<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * A user of a custom role holding exactly $grants (permission => scope,
 * null for a permission without scope row, i.e. « all » when scoped).
 *
 * @param  array<string, AccessScope|null>  $grants
 */
function svUser(array $grants): User
{
    $user = userWithPermissions(array_keys($grants));
    $role = $user->roles()->firstOrFail();
    foreach ($grants as $permission => $scope) {
        if ($scope !== null) {
            DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => $permission, 'scope' => $scope->value]);
        }
    }
    Scopes::forget();

    return $user;
}

/**
 * Sets who created and who is responsible for $record, without events.
 */
function svOwn(Model $record, ?User $creator, ?User $responsible = null, bool $withResponsible = true): Model
{
    $values = ['created_by' => $creator?->id];
    if ($withResponsible) {
        $values['responsible_user_id'] = $responsible?->id;
    }
    DB::table($record->getTable())->where('id', $record->getKey())->update($values);

    return $record->fresh();
}

function svActivity(array $attributes): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id,
        'subject' => 'Appel',
        'due_at' => now()->addDay(),
        ...$attributes,
    ]);
}

it('own scope sees responsible or created fiches only', function () {
    $me = svUser([Permissions::FICHES_VIEW => AccessScope::Own, Permissions::FICHES_UPDATE => AccessScope::Own]);
    $colleague = commercial();
    $responsible = svOwn(Organisation::query()->create(['name' => 'A Responsable']), $colleague, $me);
    $created = svOwn(Organisation::query()->create(['name' => 'B Créée']), $me, null);
    $other = svOwn(Organisation::query()->create(['name' => 'C Collègue']), $colleague, null);

    $this->actingAs($me);
    $names = collect($this->get('/crm/organisations')->assertOk()->original->getData()['page']['props']['organisations']['data'])->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['A Responsable', 'B Créée']);
    $this->get("/crm/organisations/{$other->id}")->assertNotFound();
    $this->get("/crm/organisations/{$created->id}")->assertOk();
    $this->get("/crm/organisations/{$responsible->id}")->assertOk();

    // The fiche he just created himself (without responsible) stays his.
    $this->post('/crm/organisations', ['name' => 'D Nouvelle'])->assertRedirect();
    $new = Organisation::query()->where('name', 'D Nouvelle')->sole();
    expect($new->responsible_user_id)->toBeNull();
    $this->get("/crm/organisations/{$new->id}")->assertOk();
    $this->put("/crm/organisations/{$new->id}", ['name' => 'D Modifiée'])->assertRedirect();
    expect($new->fresh()->name)->toBe('D Modifiée');
});

it('own scope on quotes follows opportunity or creator', function () {
    $me = svUser([Permissions::QUOTES_VIEW => AccessScope::Own]);
    $colleague = commercial();
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $mineOpp = svOwn(makeOpportunity($org, ['title' => 'À moi']), $colleague, $me);
    $theirOpp = svOwn(makeOpportunity($org, ['title' => 'À eux']), $colleague, $colleague);

    $onMine = svOwn(makeQuote($mineOpp), $colleague, withResponsible: false);
    $byMe = svOwn(makeQuote($theirOpp), $me, withResponsible: false);
    $theirs = svOwn(makeQuote($theirOpp), $colleague, withResponsible: false);

    $ids = CrmVisibility::quotes(Quote::query(), $me)->pluck('id')->sort()->values()->all();
    expect($ids)->toBe(collect([$onMine->id, $byMe->id])->sort()->values()->all());

    $this->actingAs($me);
    $this->get("/crm/quotes/{$theirs->id}")->assertNotFound();
    $this->get("/crm/quotes/{$byMe->id}")->assertOk();
});

it('own activities include those of own opportunities', function () {
    $me = svUser([Permissions::ACTIVITIES_VIEW => AccessScope::Own]);
    $colleague = commercial();
    $org = svOwn(Organisation::query()->create(['name' => 'Le Bistrot']), $colleague, $colleague);
    $mineOpp = svOwn(makeOpportunity($org, ['title' => 'À moi']), $colleague, $me);
    $theirOpp = svOwn(makeOpportunity($org, ['title' => 'À eux']), $colleague, $colleague);

    $responsible = svOwn(svActivity(['organisation_id' => $org->id, 'subject' => 'Responsable']), $colleague, $me);
    $created = svOwn(svActivity(['organisation_id' => $org->id, 'subject' => 'Créée']), $me, $colleague);
    $onMyOpp = svOwn(svActivity(['organisation_id' => $org->id, 'opportunity_id' => $mineOpp->id, 'subject' => 'Sur mon opportunité']), $colleague, $colleague);
    $theirs = svOwn(svActivity(['organisation_id' => $org->id, 'opportunity_id' => $theirOpp->id, 'subject' => 'À eux']), $colleague, $colleague);

    $subjects = CrmVisibility::activities(Activity::query(), $me)->pluck('subject')->sort()->values()->all();
    expect($subjects)->toBe(['Créée', 'Responsable', 'Sur mon opportunité'])
        ->and(CrmVisibility::allows($me, Permissions::ACTIVITIES_VIEW, $theirs))->toBeFalse()
        ->and(CrmVisibility::allows($me, Permissions::ACTIVITIES_VIEW, $onMyOpp))->toBeTrue()
        ->and(CrmVisibility::allows($me, Permissions::ACTIVITIES_UPDATE, $responsible))->toBeFalse()
        ->and(CrmVisibility::allows($me, Permissions::ACTIVITIES_VIEW, $created))->toBeTrue();
});

it('view das update all stays 404 outside das', function () {
    $me = svUser([Permissions::FICHES_VIEW => AccessScope::Das, Permissions::FICHES_UPDATE => null]);
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    followDas($me, $mine);
    $inside = Organisation::query()->create(['name' => 'Dedans']);
    $inside->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $outside = Organisation::query()->create(['name' => 'Dehors']);

    $this->actingAs($me);
    $this->get("/crm/organisations/{$outside->id}")->assertNotFound();
    $this->put("/crm/organisations/{$outside->id}", ['name' => 'Piraté'])->assertNotFound();
    $this->delete("/crm/organisations/{$outside->id}")->assertNotFound();
    expect($outside->fresh()->name)->toBe('Dehors');

    $this->put("/crm/organisations/{$inside->id}", ['name' => 'Dedans modifiée', 'das_ids' => [$mine->id]])->assertRedirect();
    expect($inside->fresh()->name)->toBe('Dedans modifiée');
});

it('view all update own', function () {
    $me = svUser([Permissions::FICHES_VIEW => null, Permissions::FICHES_UPDATE => AccessScope::Own, Permissions::FICHES_DELETE => AccessScope::Own]);
    $colleague = commercial();
    $theirs = svOwn(Organisation::query()->create(['name' => 'Collègue']), $colleague, $colleague);
    $mine = svOwn(Organisation::query()->create(['name' => 'Moi']), $colleague, $me);

    $this->actingAs($me);
    $can = $this->get("/crm/organisations/{$theirs->id}")->assertOk()->original->getData()['page']['props']['can'];
    expect($can['update'])->toBeFalse()->and($can['delete'])->toBeFalse();
    $this->put("/crm/organisations/{$theirs->id}", ['name' => 'Piraté'])->assertForbidden();
    $this->delete("/crm/organisations/{$theirs->id}")->assertForbidden();

    $can = $this->get("/crm/organisations/{$mine->id}")->assertOk()->original->getData()['page']['props']['can'];
    expect($can['update'])->toBeTrue()->and($can['delete'])->toBeTrue();
});

it('kanban card of another owner cannot be dragged', function () {
    $me = svUser([Permissions::OPPORTUNITIES_VIEW => null, Permissions::OPPORTUNITIES_UPDATE => AccessScope::Own]);
    $colleague = commercial();
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $mine = svOwn(makeOpportunity($org, ['title' => 'À moi']), $colleague, $me);
    $theirs = svOwn(makeOpportunity($org, ['title' => 'À eux']), $colleague, $colleague);

    $this->actingAs($me);
    $cards = collect($this->get('/crm/opportunities/kanban')->assertOk()->original->getData()['page']['props']['columns'])
        ->flatMap(fn (array $c) => $c['cards'])->keyBy('title');

    expect($cards['À moi']['can_update'])->toBeTrue()
        ->and($cards['À eux']['can_update'])->toBeFalse();

    $this->postJson("/crm/opportunities/{$theirs->id}/stage", ['stage_id' => pipelineStage('Contacté')->id])->assertForbidden();
    $this->post("/crm/opportunities/{$mine->id}/stage", ['stage_id' => pipelineStage('Contacté')->id])->assertSessionHasNoErrors();
    expect($theirs->fresh()->stage_id)->toBe(pipelineStage('Nouveau')->id)
        ->and($mine->fresh()->stage_id)->toBe(pipelineStage('Contacté')->id);
});

it('export and kanban use the view scope', function () {
    $me = svUser([Permissions::OPPORTUNITIES_VIEW => AccessScope::Own, Permissions::EXPORT => null]);
    $colleague = commercial();
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    svOwn(makeOpportunity($org, ['title' => 'À moi']), $colleague, $me);
    svOwn(makeOpportunity($org, ['title' => 'À eux']), $colleague, $colleague);

    $this->actingAs($me);
    $response = $this->get('/crm/opportunities/export')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $titles = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $titles[] = (string) $row->toArray()[0];
        }
        break;
    }
    $reader->close();
    unlink($path);

    expect($titles)->toBe(['Objet', 'À moi']);

    $cards = collect($this->get('/crm/opportunities/kanban')->assertOk()->original->getData()['page']['props']['columns'])
        ->flatMap(fn (array $c) => $c['cards'])->pluck('title')->all();
    expect($cards)->toBe(['À moi']);
});

it('followed das editable for any das scoped role', function () {
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $scoped = svUser([Permissions::OPPORTUNITIES_VIEW => AccessScope::Das]);
    $plain = svUser([Permissions::OPPORTUNITIES_VIEW => null]);
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->put("/admin/users/{$scoped->id}/das", ['das_ids' => [$das->id]])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('user_das')->where('user_id', $scoped->id)->pluck('das_id')->all())->toBe([$das->id]);

    $this->put("/admin/users/{$plain->id}/das", ['das_ids' => [$das->id]])->assertSessionHasErrors('das_ids');
    expect(DB::table('user_das')->where('user_id', $plain->id)->count())->toBe(0);

    $rows = collect($this->get('/admin/users')->assertOk()->original->getData()['page']['props']['users'])->keyBy('id');
    expect($rows[$scoped->id]['follows_das'])->toBeTrue()
        ->and($rows[$plain->id]['follows_das'])->toBeFalse();
});

it('cleared when the new role has no das scope', function () {
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $admin = admin(twoFactor: true);

    // Commercial given « Ses DAS » on one right: keeps his DAS when he becomes one.
    $commercial = RoleModel::query()->where('name', Role::Commercial->value)->sole();
    DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $commercial->id, 'permission' => Permissions::OPPORTUNITIES_VIEW, 'scope' => 'das']);
    $kept = userWithRole(Role::ResponsableDas);
    followDas($kept, $das);
    $this->actingAs($admin)->patchJson("/admin/users/{$kept->id}/role", ['role' => 'commercial'])->assertRedirect();
    expect(DB::table('user_das')->where('user_id', $kept->id)->count())->toBe(1);

    // Then assistant (no « Ses DAS »): emptied, audited.
    $this->patchJson("/admin/users/{$kept->id}/role", ['role' => 'assistant'])->assertRedirect();
    expect(DB::table('user_das')->where('user_id', $kept->id)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event', 'users.das_changed')->where('auditable_id', $kept->id)->count())->toBe(1);
});

it('no domain right hides the menu', function () {
    $me = svUser([Permissions::EXPORT => null]);
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    svActivity(['organisation_id' => $org->id, 'responsible_user_id' => $me->id, 'due_at' => now()->subDay()]);

    $this->actingAs($me);
    $this->get('/crm/organisations')->assertForbidden();
    $props = $this->get('/dashboard')->assertOk()->original->getData()['page']['props'];
    $todo = $props['todo_count'];
    expect($todo instanceof Closure ? $todo() : $todo)->toBe(0)
        ->and(CrmVisibility::organisations(Organisation::query(), $me)->count())->toBe(0)
        ->and(CrmVisibility::activities(Activity::query(), $me)->count())->toBe(0);
});

it('shows on a fiche only the domains the user may see', function () {
    $me = svUser([Permissions::FICHES_VIEW => null]);
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['title' => 'HACCP']);
    makeQuote($opportunity);
    svActivity(['organisation_id' => $org->id, 'responsible_user_id' => $me->id, 'due_at' => now()->subDay()]);

    $this->actingAs($me);
    $props = $this->get("/crm/organisations/{$org->id}")->assertOk()->original->getData()['page']['props'];
    expect($props['opportunities'])->toBe([])->and($props['quotes'])->toBe([]);

    $types = collect($this->getJson("/crm/organisations/{$org->id}/timeline")->assertOk()->json('entries'))->pluck('type')->unique()->values()->all();
    expect($types)->not->toContain('opportunity')->not->toContain('quote')->not->toContain('activity');

    $todo = $this->get('/dashboard')->original->getData()['page']['props']['todo_count'];
    expect($todo instanceof Closure ? $todo() : $todo)->toBe(0);
});

it('flags each action with its own rights on the fiche and the opportunity', function () {
    $me = svUser([
        Permissions::FICHES_VIEW => null, Permissions::OPPORTUNITIES_VIEW => null,
        Permissions::ACTIVITIES_VIEW => null, Permissions::ACTIVITIES_UPDATE => AccessScope::Own, Permissions::ACTIVITIES_DELETE => AccessScope::Own,
    ]);
    $colleague = commercial();
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['title' => 'HACCP']);
    $mine = svOwn(svActivity(['organisation_id' => $org->id, 'opportunity_id' => $opportunity->id, 'subject' => 'À moi', 'due_at' => now()->subHour()]), $me, $me);
    $theirs = svOwn(svActivity(['organisation_id' => $org->id, 'opportunity_id' => $opportunity->id, 'subject' => 'À eux']), $colleague, $colleague);

    $this->actingAs($me);
    $entries = collect($this->getJson("/crm/organisations/{$org->id}/timeline")->assertOk()->json('entries'))
        ->where('type', 'activity')->keyBy('title');
    expect($entries['À moi']['meta'])->toMatchArray(['can_update' => true, 'can_delete' => true])
        ->and($entries['À eux']['meta'])->toMatchArray(['can_update' => false, 'can_delete' => false]);

    $rows = collect($this->get("/crm/opportunities/{$opportunity->id}")->assertOk()->original->getData()['page']['props']['activities'])->keyBy('subject');
    expect($rows['À moi'])->toMatchArray(['can_update' => true, 'can_delete' => true])
        ->and($rows['À eux'])->toMatchArray(['can_update' => false, 'can_delete' => false]);

    $this->delete("/crm/activities/{$theirs->id}")->assertForbidden();
    $todo = $this->get('/crm/todo')->assertOk()->original->getData()['page']['props']['sections'];
    $todoRows = collect($todo)->flatten(1)->keyBy('subject');
    expect($todoRows['À moi']['can_update'] ?? null)->toBeTrue();
    expect($mine->fresh())->not->toBeNull();
});
