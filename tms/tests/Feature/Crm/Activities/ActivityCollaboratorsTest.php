<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

/** A user who sees and does his own actions only (« Ses fiches »). */
function colOwnUser(): User
{
    $user = userWithPermissions([Permissions::ACTIVITIES_VIEW, Permissions::ACTIVITIES_UPDATE, Permissions::FICHES_VIEW]);
    $role = $user->roles()->firstOrFail();
    foreach ([Permissions::ACTIVITIES_VIEW, Permissions::ACTIVITIES_UPDATE, Permissions::FICHES_VIEW] as $permission) {
        DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => $permission, 'scope' => 'own']);
    }
    Scopes::forget();

    return $user;
}

function colType(string $label = 'Rendez-vous physique'): string
{
    return crmItem(ReferenceList::ActionType, $label)->id;
}

it('adds collaborators to a meeting: only active users who may work on the actions, never the responsible', function () {
    $me = commercial();
    $boss = direction();
    $relations = userWithRole(Role::ChargeRelations);
    $trainer = userWithRole(Role::Formateur);
    $gone = commercial();
    $gone->forceFill(['deactivated_at' => now()])->save();
    $this->actingAs($me);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);

    $this->get('/crm/activities/options')->assertOk()
        ->assertJsonPath('collaborators', fn (array $options) => collect($options)->pluck('value')->contains($boss->id)
            && collect($options)->pluck('value')->contains($relations->id)
            // Lot 1e: a trainer holds crm.activities.view (his follow-up calls), so he may join an action.
            && collect($options)->pluck('value')->contains($trainer->id)
            && ! collect($options)->pluck('value')->contains($gone->id));

    $this->post('/crm/activities', [
        'type_id' => colType(), 'organisation_id' => $org->id, 'subject' => 'RDV direction',
        'due_at' => '2026-11-24T14:30', 'collaborator_ids' => [$boss->id, $relations->id],
    ])->assertSessionHasNoErrors();
    $activity = Activity::query()->sole();
    expect($activity->collaborators()->pluck('users.id')->sort()->values()->all())->toBe(collect([$boss->id, $relations->id])->sort()->values()->all());

    foreach ([[$gone->id], [$me->id]] as $ids) {
        $this->put("/crm/activities/{$activity->id}", ['type_id' => colType(), 'subject' => 'RDV', 'collaborator_ids' => $ids])
            ->assertSessionHasErrors(['collaborator_ids' => 'Choisissez des collaborateurs qui ont accès aux actions (pas le responsable).']);
    }

    // « Modifier » replaces them; the show reads them.
    $this->put("/crm/activities/{$activity->id}", ['type_id' => colType(), 'subject' => 'RDV', 'collaborator_ids' => [$relations->id]])->assertSessionHasNoErrors();
    $this->getJson("/crm/activities/{$activity->id}")->assertJsonPath('activity.collaborators.0.id', $relations->id)
        ->assertJsonPath('activity.collaborators.0.name', $relations->name);
});

it('lets a user the action is also assigned to see and change it, in « À faire » and the planning, never delete it', function () {
    $owner = colOwnUser();
    $helper = colOwnUser();
    $this->actingAs($owner);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac', 'responsible_user_id' => $owner->id]);
    $this->post('/crm/activities', [
        'type_id' => colType(), 'organisation_id' => $org->id, 'subject' => 'RDV à deux',
        'due_at' => '2026-11-19T15:00', 'collaborator_ids' => [$helper->id],
    ])->assertSessionHasNoErrors();
    $activity = Activity::query()->sole();

    $this->actingAs($helper);
    $this->getJson("/crm/activities/{$activity->id}")->assertOk()->assertJsonPath('can.update', true)->assertJsonPath('can.delete', false);
    $this->put("/crm/activities/{$activity->id}", ['type_id' => colType(), 'subject' => 'Changé'])->assertSessionHasNoErrors();
    expect($activity->fresh()->subject)->toBe('Changé')
        ->and($activity->fresh()->responsible_user_id)->toBe($owner->id)
        ->and($activity->collaborators()->pluck('users.id')->all())->toBe([$helper->id]);
    $this->get('/crm/todo')->assertInertia(fn (Assert $p) => $p->where('sections', fn ($sections) => collect($sections)->flatten(1)->pluck('id')->contains($activity->id)));
    $this->get('/crm/planning?week=2026-11-16')->assertInertia(fn (Assert $p) => $p->where('days', fn ($days) => collect($days)->flatMap(fn ($d) => $d['items'])->pluck('id')->contains($activity->id)));
});
