<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\TodoQuery;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function todoAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Paris');
}

/**
 * An action to do.
 *
 * @param  array<string, mixed>  $attributes
 */
function todoAction(User $responsible, string $due, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id,
        'subject' => 'Relance '.$due,
        'due_at' => todoAt($due),
        'organisation_id' => ($attributes['organisation_id'] ?? Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac'])->id),
        'responsible_user_id' => $responsible->id,
        ...$attributes,
    ]);
}

/**
 * @param  array{overdue: mixed, today: mixed, week: mixed}  $todo
 * @return array<string, list<string>>
 */
function todoSubjects(array $todo): array
{
    return array_map(fn ($rows) => collect($rows)->pluck('subject')->all(), $todo);
}

it('overdue boundary uses Europe/Paris midnight', function () {
    // 14/11 00:10 Paris is still 13/11 23:10 UTC.
    $this->travelTo(todoAt('2026-11-14 00:10:00'));
    $user = commercial();
    $this->actingAs($user);
    todoAction($user, '2026-11-13 23:30:00', ['subject' => 'Hier soir']);
    todoAction($user, '2026-11-14 00:05:00', ['subject' => 'Cette nuit']);

    expect(todoSubjects(TodoQuery::for($user, CarbonImmutable::now())))
        ->toBe(['overdue' => ['Hier soir'], 'today' => ['Cette nuit'], 'week' => []]);

    // Before midnight Paris, an action due at 23:55 is not late yet.
    $this->travelTo(todoAt('2026-11-13 23:50:00'));
    expect(todoSubjects(TodoQuery::for($user, CarbonImmutable::now())))
        ->toBe(['overdue' => [], 'today' => ['Hier soir'], 'week' => ['Cette nuit']]);
});

it('orders by due date and stops this week on Sunday evening', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00')); // Monday
    $user = commercial();
    $this->actingAs($user);
    todoAction($user, '2026-11-22 23:00:00', ['subject' => 'Dimanche soir']);
    todoAction($user, '2026-11-17 09:00:00', ['subject' => 'Mardi']);
    todoAction($user, '2026-11-23 09:00:00', ['subject' => 'Lundi prochain']);
    todoAction($user, '2026-11-12 09:00:00', ['subject' => 'Ancienne']);
    todoAction($user, '2026-11-14 09:00:00', ['subject' => 'Plus récente']);

    expect(todoSubjects(TodoQuery::for($user, CarbonImmutable::now())))
        ->toBe(['overdue' => ['Ancienne', 'Plus récente'], 'today' => [], 'week' => ['Mardi', 'Dimanche soir']]);
});

it('lists only my actions to do, never done, trashed or without due date', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00'));
    $user = commercial();
    $other = commercial();
    $this->actingAs($user);
    todoAction($user, '2026-11-16 11:00:00', ['subject' => 'À moi']);
    todoAction($other, '2026-11-16 11:00:00', ['subject' => 'À un autre']);
    todoAction($user, '2026-11-16 11:00:00', ['subject' => 'Déjà faite', 'done_at' => now()->subHour()]);
    todoAction($user, '2026-11-16 11:00:00', ['subject' => 'Supprimée'])->delete();
    $gone = Organisation::query()->create(['name' => 'À la corbeille']);
    todoAction($user, '2026-11-16 11:00:00', ['subject' => 'Fiche supprimée', 'organisation_id' => $gone->id]);
    $gone->delete();

    expect(todoSubjects(TodoQuery::for($user, CarbonImmutable::now()))['today'])->toBe(['À moi']);
});

// The responsible always sees his own actions, even on a fiche or an
// opportunity out of his DAS (« Confier à… », 0.7.1).
it('todo shows a responsable das his own actions, even out of his das', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00'));
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $visible = Organisation::query()->create(['name' => 'Visible']);
    $visible->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $hidden = Organisation::query()->create(['name' => 'Cachée']);
    $hidden->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    $this->actingAs($responsable);
    todoAction($responsable, '2026-11-16 11:00:00', ['subject' => 'Sur fiche visible', 'organisation_id' => $visible->id]);
    todoAction($responsable, '2026-11-16 12:00:00', ['subject' => 'Sur fiche cachée', 'organisation_id' => $hidden->id]);
    $foreign = makeOpportunity($visible, ['title' => 'Autre DAS', 'das_id' => $other->id]);
    todoAction($responsable, '2026-11-16 13:00:00', ['subject' => 'Opportunité autre DAS', 'organisation_id' => $visible->id, 'opportunity_id' => $foreign->id]);

    expect(todoSubjects(TodoQuery::for($responsable, CarbonImmutable::now()))['today'])->toBe(['Sur fiche visible', 'Sur fiche cachée', 'Opportunité autre DAS'])
        ->and(TodoQuery::badge($responsable))->toBe(3);

    $this->get('/crm/todo?team=1')->assertOk()
        ->assertInertia(fn ($p) => $p->where('team', false)->where('can_team', false)->has('sections.today', 3)
            ->where('sections.today.0.fiche_visible', true)
            ->where('sections.today.1.fiche_visible', false)
            ->where('sections.today.2.fiche_visible', false));
});

it('lets direction and admin see the actions of the team', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00'));
    $seller = commercial();
    $boss = direction(twoFactor: true);
    todoAction($seller, '2026-11-16 11:00:00', ['subject' => 'Action du commercial']);
    todoAction($boss, '2026-11-16 12:00:00', ['subject' => 'Action de la direction']);

    $this->actingAs($boss)->get('/crm/todo')->assertOk()
        ->assertInertia(fn ($p) => $p->component('crm/todo')->where('can_team', true)->where('team', false)->has('sections.today', 1));
    $this->get('/crm/todo?team=1')->assertOk()
        ->assertInertia(fn ($p) => $p->where('team', true)->has('sections.today', 2)->where('sections.today.0.responsible', $seller->name));

    $this->actingAs(admin(twoFactor: true))->get('/crm/todo?team=1')->assertInertia(fn ($p) => $p->where('team', true)->has('sections.today', 2));

    // Others only ever get their own.
    $this->actingAs($seller)->get('/crm/todo?team=1')->assertOk()
        ->assertInertia(fn ($p) => $p->where('team', false)->where('can_team', false)->has('sections.today', 1));
});

it('renders the page with the three sections', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00'));
    $user = commercial();
    todoAction($user, '2026-11-10 09:00:00');
    todoAction($user, '2026-11-16 15:00:00');
    todoAction($user, '2026-11-18 09:00:00');

    $this->actingAs($user)->get('/crm/todo')->assertOk()
        ->assertInertia(fn ($p) => $p->component('crm/todo')
            ->has('sections.overdue', 1)->has('sections.today', 1)->has('sections.week', 1)
            ->where('sections.overdue.0.status', 'overdue')
            ->where('can_edit', true));
});

it('postpones an action by updating its due date only', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00'));
    $user = commercial();
    $this->actingAs($user);
    $action = todoAction($user, '2026-11-12 14:30:00', ['subject' => 'Rappeler', 'report' => 'à garder']);

    $this->from('/crm/todo')->patch("/crm/activities/{$action->id}/due", ['due_at' => '2026-11-17'])
        ->assertSessionHasNoErrors()->assertRedirect('/crm/todo');

    $action->refresh();
    expect($action->due_at?->equalTo(todoAt('2026-11-17 09:00:00')))->toBeTrue()
        ->and($action->subject)->toBe('Rappeler')->and($action->report)->toBe('à garder')->and($action->done_at)->toBeNull();

    $this->patch("/crm/activities/{$action->id}/due", ['due_at' => '2026-11-18T15:45'])->assertSessionHasNoErrors();
    expect($action->refresh()->due_at?->equalTo(todoAt('2026-11-18 15:45:00')))->toBeTrue();
});

it('refuses to postpone to the past, without date, or a done action', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00'));
    $user = commercial();
    $this->actingAs($user);
    $action = todoAction($user, '2026-11-16 14:00:00');
    $done = todoAction($user, '2026-11-12 14:00:00', ['done_at' => now()->subDay()]);

    $this->patch("/crm/activities/{$action->id}/due", ['due_at' => '2026-11-15'])->assertSessionHasErrors('due_at');
    $this->patch("/crm/activities/{$action->id}/due", [])->assertSessionHasErrors('due_at');
    $this->patch("/crm/activities/{$action->id}/due", ['due_at' => 'demain'])->assertSessionHasErrors('due_at');
    $this->patch("/crm/activities/{$done->id}/due", ['due_at' => '2026-11-20'])->assertSessionHasErrors('due_at');
    expect($action->refresh()->due_at?->equalTo(todoAt('2026-11-16 14:00:00')))->toBeTrue();
});

it('postpone needs crm.activities.update and the visibility of the action', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00'));
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $org = Organisation::query()->create(['name' => 'Cachée']);
    $org->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);
    $seller = commercial();
    $action = todoAction($seller, '2026-11-16 14:00:00', ['organisation_id' => $org->id]);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    $this->actingAs($responsable)->patch("/crm/activities/{$action->id}/due", ['due_at' => '2026-11-20'])->assertNotFound();

    $this->actingAs(direction(twoFactor: true))->patch("/crm/activities/{$action->id}/due", ['due_at' => '2026-11-20'])->assertForbidden();
    expect($action->refresh()->due_at?->equalTo(todoAt('2026-11-16 14:00:00')))->toBeTrue();
});

it('counts overdue plus today in the shared badge, cached per user under the tenant id', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00'));
    $user = commercial();
    todoAction($user, '2026-11-10 09:00:00');
    todoAction($user, '2026-11-16 15:00:00');
    todoAction($user, '2026-11-18 09:00:00'); // this week: not counted
    todoAction(commercial(), '2026-11-16 15:00:00'); // someone else's

    $this->actingAs($user)->get('/crm/todo')->assertInertia(fn ($p) => $p->where('todo_count', 2));

    expect(Cache::has(tenant()->id.':todo-badge:'.$user->id))->toBeTrue();
    // Cached for 60 seconds: a change made behind the model's back shows after that.
    DB::table('activities')->where('responsible_user_id', $user->id)->delete();
    $this->get('/crm/todo')->assertInertia(fn ($p) => $p->where('todo_count', 2));
    $this->travel(61)->seconds();
    $this->get('/crm/todo')->assertInertia(fn ($p) => $p->where('todo_count', 0));
});

it('invalidates the badge when an action is created, completed, postponed, reassigned or deleted', function () {
    $this->travelTo(todoAt('2026-11-16 10:00:00'));
    $user = commercial();
    $colleague = commercial();
    $this->actingAs($user);
    $key = fn (User $u) => tenant()->id.':todo-badge:'.$u->id;
    $count = fn (User $u) => TodoQuery::badge($u);

    expect($count($user))->toBe(0)->and($count($colleague))->toBe(0);

    $action = todoAction($user, '2026-11-16 15:00:00');
    expect($count($user))->toBe(1);

    $this->post("/crm/activities/{$action->id}/done")->assertSessionHasNoErrors();
    expect($count($user))->toBe(0);

    $second = todoAction($user, '2026-11-16 16:00:00');
    expect($count($user))->toBe(1);
    $this->patch("/crm/activities/{$second->id}/due", ['due_at' => '2026-11-20'])->assertSessionHasNoErrors();
    expect($count($user))->toBe(0);
    $this->patch("/crm/activities/{$second->id}/due", ['due_at' => '2026-11-16T18:00'])->assertSessionHasNoErrors();
    expect($count($user))->toBe(1);

    // Reassigned: old and new responsible both refreshed.
    expect($count($colleague))->toBe(0);
    $second->forceFill(['responsible_user_id' => $colleague->id])->save();
    expect(Cache::has($key($user)))->toBeFalse()->and(Cache::has($key($colleague)))->toBeFalse()
        ->and($count($user))->toBe(0)->and($count($colleague))->toBe(1);

    $second->delete();
    expect($count($colleague))->toBe(0);
});
