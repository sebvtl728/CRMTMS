<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// « Modifier » of an action (À faire, planning, fiches): the dialog reads
// the action with GET and saves with PUT.

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function aeType(string $label): string
{
    return crmItem(ReferenceList::ActionType, $label)->id;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function aeTodo(array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => aeType('Appel'),
        'subject' => 'Rappeler le chef',
        'due_at' => CarbonImmutable::parse('2026-11-20 09:00', 'Europe/Paris'),
        ...$attributes,
    ]);
}

it('edits an action to do: subject, due date and time, duration, place and responsible', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
    $me = commercial();
    $colleague = commercial();
    $this->actingAs($me);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $todo = aeTodo(['organisation_id' => $org->id]);

    $this->from('/crm/todo')->put("/crm/activities/{$todo->id}", [
        'type_id' => aeType('Rendez-vous physique'),
        'subject' => 'RDV avec le chef',
        'due_at' => '2026-11-24T14:30',
        'duration_minutes' => 90,
        'location' => 'Annecy',
        'responsible_user_id' => $colleague->id,
    ])->assertSessionHasNoErrors()->assertRedirect('/crm/todo')->assertSessionHas('status', 'Action modifiée.');

    $todo->refresh();
    expect($todo->subject)->toBe('RDV avec le chef')
        ->and($todo->type_id)->toBe(aeType('Rendez-vous physique'))
        ->and($todo->due_at?->equalTo(CarbonImmutable::parse('2026-11-24 14:30', 'Europe/Paris')))->toBeTrue()
        ->and($todo->duration_minutes)->toBe(90)
        ->and($todo->location)->toBe('Annecy')
        ->and($todo->responsible_user_id)->toBe($colleague->id)
        ->and($todo->done_at)->toBeNull()
        ->and($todo->organisation_id)->toBe($org->id)
        ->and($org->refresh()->last_contact_at)->toBeNull();
});

it('gives the edit dialog the action, its choices and the responsible options', function () {
    $me = commercial();
    $this->actingAs($me);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $todo = aeTodo(['organisation_id' => $org->id, 'responsible_user_id' => $me->id, 'duration_minutes' => 45]);

    $this->getJson("/crm/activities/{$todo->id}")
        ->assertOk()
        ->assertJsonPath('activity.subject', 'Rappeler le chef')
        ->assertJsonPath('activity.type_id', aeType('Appel'))
        ->assertJsonPath('activity.duration_minutes', 45)
        ->assertJsonPath('activity.responsible_user_id', $me->id)
        ->assertJsonPath('activity.organisation.name', 'Hôtel du Lac')
        ->assertJsonPath('can.update', true)
        ->assertJsonFragment(['value' => $me->id, 'label' => $me->name]);

    // A type the organisme deactivated since stays offered for this action only.
    crmItem(ReferenceList::ActionType, 'Appel')->update(['is_active' => false]);
    $this->getJson("/crm/activities/{$todo->id}")->assertOk()->assertJsonFragment(['value' => aeType('Appel'), 'label' => 'Appel (inactif)', 'code' => 'appel']);
    $this->getJson('/crm/activities/options')->assertOk()->assertJsonMissing(['value' => aeType('Appel')]);
});

it('validates the edit in French and keeps an action to do dated', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $todo = aeTodo(['organisation_id' => $org->id]);

    $this->put("/crm/activities/{$todo->id}", ['type_id' => aeType('Appel'), 'subject' => ''])
        ->assertSessionHasErrors(['subject' => 'Indiquez l\'objet de l\'action.']);
    $this->put("/crm/activities/{$todo->id}", ['type_id' => aeType('Appel'), 'subject' => 'x', 'due_at' => 'demain'])
        ->assertSessionHasErrors(['due_at' => 'Indiquez une date valide.']);
    $this->put("/crm/activities/{$todo->id}", ['type_id' => aeType('Appel'), 'subject' => 'x', 'due_at' => ''])
        ->assertSessionHasErrors(['due_at' => 'Indiquez l\'échéance de l\'action à faire.']);
    $this->put("/crm/activities/{$todo->id}", ['type_id' => aeType('Appel'), 'subject' => 'x', 'duration_minutes' => 0])
        ->assertSessionHasErrors(['duration_minutes' => 'Indiquez une durée entre 1 et 1 440 minutes.']);

    expect($todo->refresh()->subject)->toBe('Rappeler le chef');
});

it('edits only the actions in the scope of crm.activities.update', function () {
    $me = userWithPermissions([Permissions::ACTIVITIES_VIEW, Permissions::ACTIVITIES_UPDATE, Permissions::FICHES_VIEW]);
    $role = $me->roles()->firstOrFail();
    DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => Permissions::ACTIVITIES_UPDATE, 'scope' => AccessScope::Own->value]);
    Scopes::forget();
    $colleague = commercial();
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $theirs = aeTodo(['organisation_id' => $org->id, 'responsible_user_id' => $colleague->id]);
    DB::table('activities')->where('id', $theirs->id)->update(['created_by' => $colleague->id]);
    $mine = aeTodo(['organisation_id' => $org->id, 'responsible_user_id' => $me->id]);

    $this->actingAs($me);
    $this->getJson("/crm/activities/{$theirs->id}")->assertOk()->assertJsonPath('can.update', false);
    $this->put("/crm/activities/{$theirs->id}", ['type_id' => aeType('Appel'), 'subject' => 'Piraté'])->assertForbidden();
    expect($theirs->refresh()->subject)->toBe('Rappeler le chef');

    $this->put("/crm/activities/{$mine->id}", ['type_id' => aeType('Appel'), 'subject' => 'À moi'])->assertSessionHasNoErrors();
    expect($mine->refresh()->subject)->toBe('À moi');
});

it('shows a Modifier right on each row of À faire and of the planning', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
    $me = commercial();
    $this->actingAs($me);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    aeTodo(['organisation_id' => $org->id, 'responsible_user_id' => $me->id, 'due_at' => CarbonImmutable::parse('2026-11-16 15:00', 'Europe/Paris')]);

    $this->get('/crm/todo')->assertInertia(fn ($page) => $page->where('sections.today.0.can_update', true)->etc());
    $this->get('/crm/planning')->assertInertia(fn ($page) => $page->where('days.0.items.0.can_update', true)->etc());
});
