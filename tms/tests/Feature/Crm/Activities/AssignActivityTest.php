<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\ActivityAssignedMail;
use App\Modules\Crm\Activities\Events\ActivityChanged;
use App\Modules\Crm\Activities\TodoQuery;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

// « Confier à… »: an action handed to another user, who is told by e-mail
// and always sees it (À faire, badge), even on a fiche he may not open.

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    Mail::fake();
    test()->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
});

function asType(string $label = 'Appel'): string
{
    return crmItem(ReferenceList::ActionType, $label)->id;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function asTodo(array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => asType(),
        'subject' => 'Rappeler le directeur',
        'purpose' => 'Présenter la formation HACCP',
        'report' => 'Compte rendu confidentiel',
        'next_steps' => 'Suite confidentielle',
        'due_at' => CarbonImmutable::parse('2026-11-16 15:30', 'Europe/Paris'),
        'organisation_id' => Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac'])->id,
        ...$attributes,
    ]);
}

/**
 * A user of a custom role holding exactly $grants (permission => scope).
 *
 * @param  array<string, AccessScope|null>  $grants
 */
function asScoped(array $grants): User
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

it('creates an action for another user and tells him by e-mail, once', function () {
    $me = commercial();
    $colleague = commercial();
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $this->actingAs($me);

    $this->post('/crm/activities', [
        'type_id' => asType(), 'organisation_id' => $org->id, 'subject' => 'Rappeler le chef',
        'done' => false, 'due_at' => '2026-11-20T09:00', 'responsible_user_id' => $colleague->id,
    ])->assertSessionHasNoErrors();

    $activity = Activity::query()->where('subject', 'Rappeler le chef')->sole();
    expect($activity->responsible_user_id)->toBe($colleague->id)
        ->and($activity->created_by)->toBe($me->id);
    Mail::assertSent(ActivityAssignedMail::class, 1);
    Mail::assertSent(ActivityAssignedMail::class, fn (ActivityAssignedMail $m) => $m->hasTo($colleague->email));
});

it('keeps the author as responsible by default, without e-mail', function () {
    $me = commercial();
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $this->actingAs($me);

    $this->post('/crm/activities', ['type_id' => asType(), 'organisation_id' => $org->id, 'done' => false, 'due_at' => '2026-11-20T09:00'])
        ->assertSessionHasNoErrors();

    expect(Activity::query()->sole()->responsible_user_id)->toBe($me->id);
    Mail::assertNothingSent();
});

it('offers the active users in « Attribuer à » of the quick entry, the author first chosen', function () {
    $me = commercial();
    $colleague = commercial();
    $gone = commercial();
    $gone->forceFill(['deactivated_at' => now()])->save();
    $this->actingAs($me);

    $this->getJson('/crm/activities/options')->assertOk()
        ->assertJsonPath('responsible_user_id', $me->id)
        ->assertJsonFragment(['value' => $colleague->id, 'label' => $colleague->name])
        ->assertJsonMissing(['value' => $gone->id]);
});

it('assigns an action to another user, who gets type, subject, due date, purpose and message, never the report', function () {
    $me = commercial();
    $colleague = commercial();
    $this->actingAs($me);
    $todo = asTodo(['responsible_user_id' => $me->id]);

    $this->from('/crm/todo')->put("/crm/activities/{$todo->id}/assign", ['responsible_user_id' => $colleague->id, 'message' => 'Tu le connais mieux que moi.'])
        ->assertSessionHasNoErrors()->assertRedirect('/crm/todo')
        ->assertSessionHas('status', "Action confiée à {$colleague->name}.");

    expect($todo->refresh()->responsible_user_id)->toBe($colleague->id);
    Mail::assertSent(ActivityAssignedMail::class, 1);
    Mail::assertSent(ActivityAssignedMail::class, function (ActivityAssignedMail $mail) use ($colleague, $me, $todo) {
        $text = $mail->render();

        return $mail->hasTo($colleague->email)
            && str_contains((string) $mail->subject, 'Rappeler le directeur')
            && str_contains($text, $me->name)
            && str_contains($text, 'Appel')
            && str_contains($text, 'Rappeler le directeur')
            && str_contains($text, '16/11/2026 à 15:30')
            && str_contains($text, 'Présenter la formation HACCP')
            && str_contains($text, 'Tu le connais mieux que moi.')
            && str_contains($text, route('crm.todo', ['action' => $todo->id]))
            && ! str_contains($text, 'Compte rendu confidentiel')
            && ! str_contains($text, 'Suite confidentielle');
    });
});

it('sends no e-mail when the action is given to oneself or to its current responsible', function () {
    $me = commercial();
    $colleague = commercial();
    $this->actingAs($me);
    $mine = asTodo(['responsible_user_id' => $colleague->id]);
    $theirs = asTodo(['responsible_user_id' => $colleague->id]);

    $this->put("/crm/activities/{$mine->id}/assign", ['responsible_user_id' => $me->id])->assertSessionHasNoErrors();
    $this->put("/crm/activities/{$theirs->id}/assign", ['responsible_user_id' => $colleague->id])->assertSessionHasNoErrors();

    expect($mine->refresh()->responsible_user_id)->toBe($me->id);
    Mail::assertNothingSent();
});

it('also tells the new responsible chosen in « Modifier »', function () {
    $me = commercial();
    $colleague = commercial();
    $this->actingAs($me);
    $todo = asTodo(['responsible_user_id' => $me->id]);

    $this->put("/crm/activities/{$todo->id}", ['type_id' => asType(), 'subject' => 'Rappeler le directeur', 'responsible_user_id' => $colleague->id])
        ->assertSessionHasNoErrors();
    $this->put("/crm/activities/{$todo->id}", ['type_id' => asType(), 'subject' => 'Rappeler le directeur bis', 'responsible_user_id' => $colleague->id])
        ->assertSessionHasNoErrors();

    Mail::assertSent(ActivityAssignedMail::class, 1);
});

it('lets the new responsible see and do the action on a fiche out of his scope, without opening the fiche', function (AccessScope $scope) {
    $me = commercial();
    $him = asScoped([
        Permissions::FICHES_VIEW => $scope, Permissions::OPPORTUNITIES_VIEW => $scope,
        Permissions::ACTIVITIES_VIEW => $scope, Permissions::ACTIVITIES_UPDATE => $scope,
    ]);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    DB::table('organisations')->where('id', $org->id)->update(['created_by' => $me->id, 'responsible_user_id' => $me->id]);
    $this->actingAs($me);
    $todo = asTodo(['organisation_id' => $org->id, 'responsible_user_id' => $me->id]);
    $other = asTodo(['organisation_id' => $org->id, 'responsible_user_id' => $me->id, 'subject' => 'Autre']);
    DB::table('activities')->whereIn('id', [$todo->id, $other->id])->update(['created_by' => $me->id]);

    $this->put("/crm/activities/{$todo->id}/assign", ['responsible_user_id' => $him->id])->assertSessionHasNoErrors();

    $this->actingAs($him);
    $this->get('/crm/todo')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('sections.today', 1)
        ->where('sections.today.0.id', $todo->id)
        ->where('sections.today.0.can_update', true)
        ->where('sections.today.0.fiche_visible', false)
        ->where('sections.today.0.organisation.name', 'Hôtel du Lac'));
    expect(TodoQuery::count($him, CarbonImmutable::now()))->toBe(1);
    $this->get("/crm/organisations/{$org->id}")->assertNotFound();
    $this->getJson("/crm/activities/{$other->id}")->assertNotFound();

    $this->put("/crm/activities/{$todo->id}", ['type_id' => asType(), 'subject' => 'Rappeler le directeur (B)'])->assertSessionHasNoErrors();
    $this->post("/crm/activities/{$todo->id}/done", [])->assertSessionHasNoErrors();
    expect($todo->refresh()->subject)->toBe('Rappeler le directeur (B)')
        ->and($todo->done_at)->not->toBeNull();
})->with([AccessScope::Das, AccessScope::Own]);

it('lets a das responsible do and edit an action handed to him out of his das, never remove it', function () {
    $me = commercial();
    $him = asScoped([
        Permissions::FICHES_VIEW => AccessScope::Das, Permissions::ACTIVITIES_VIEW => AccessScope::Das,
        Permissions::ACTIVITIES_UPDATE => AccessScope::Das, Permissions::ACTIVITIES_DELETE => AccessScope::Das,
    ]);
    $this->actingAs($me);
    $todo = asTodo(['responsible_user_id' => $me->id]);
    $this->put("/crm/activities/{$todo->id}/assign", ['responsible_user_id' => $him->id])->assertSessionHasNoErrors();

    $this->actingAs($him);
    $this->get('/crm/todo')->assertInertia(fn (Assert $page) => $page
        ->where('sections.today.0.can_update', true)->where('sections.today.0.can_delete', false));
    $this->delete("/crm/activities/{$todo->id}")->assertForbidden();
    expect(Activity::query()->whereKey($todo->id)->exists())->toBeTrue();

    $this->put("/crm/activities/{$todo->id}", ['type_id' => asType(), 'subject' => 'Modifiée par B'])->assertSessionHasNoErrors();
    $this->post("/crm/activities/{$todo->id}/done", [])->assertSessionHasNoErrors();
    expect($todo->refresh()->subject)->toBe('Modifiée par B')->and($todo->done_at)->not->toBeNull();
});

it('marks the fiche as openable in À faire when the viewer may see it', function () {
    $me = commercial();
    $this->actingAs($me);
    asTodo(['responsible_user_id' => $me->id]);

    $this->get('/crm/todo')->assertInertia(fn (Assert $page) => $page->where('sections.today.0.fiche_visible', true));
});

it('assigns only an action in the scope of crm.activities.update', function () {
    $me = asScoped([Permissions::ACTIVITIES_VIEW => null, Permissions::ACTIVITIES_UPDATE => AccessScope::Own, Permissions::FICHES_VIEW => null]);
    $colleague = commercial();
    $theirs = asTodo(['responsible_user_id' => $colleague->id]);
    DB::table('activities')->where('id', $theirs->id)->update(['created_by' => $colleague->id]);

    $this->actingAs($me)->put("/crm/activities/{$theirs->id}/assign", ['responsible_user_id' => $me->id])->assertNotFound();
    expect($theirs->refresh()->responsible_user_id)->toBe($colleague->id);

    $this->actingAs(userWithPermissions([Permissions::ACTIVITIES_VIEW]))
        ->put("/crm/activities/{$theirs->id}/assign", ['responsible_user_id' => $colleague->id])->assertForbidden();
});

it('refuses in French a deactivated user, a user of another organisme and a too long message', function () {
    $me = commercial();
    $gone = commercial();
    $gone->forceFill(['deactivated_at' => now()])->save();
    $stranger = userIn(Tenant::factory()->create());
    $this->actingAs($me);
    $todo = asTodo(['responsible_user_id' => $me->id]);

    $this->put("/crm/activities/{$todo->id}/assign", ['responsible_user_id' => $gone->id])
        ->assertSessionHasErrors(['responsible_user_id' => 'Choisissez un utilisateur actif de l\'organisme.']);
    $this->put("/crm/activities/{$todo->id}/assign", ['responsible_user_id' => $stranger->id])
        ->assertSessionHasErrors(['responsible_user_id' => 'Choisissez un utilisateur actif de l\'organisme.']);
    $this->put("/crm/activities/{$todo->id}/assign", [])
        ->assertSessionHasErrors(['responsible_user_id' => 'Choisissez à qui confier l\'action.']);
    $this->put("/crm/activities/{$todo->id}/assign", ['responsible_user_id' => commercial()->id, 'message' => str_repeat('a', 501)])
        ->assertSessionHasErrors(['message' => 'Le message ne peut pas dépasser 500 caractères.']);

    expect($todo->refresh()->responsible_user_id)->toBe($me->id);
    Mail::assertNothingSent();
});

it('shows « Action confiée à B par A » in the history of the fiche, journaled without personal value', function () {
    $me = commercial();
    $colleague = commercial();
    $this->actingAs($me);
    $todo = asTodo(['responsible_user_id' => $me->id]);

    $this->put("/crm/activities/{$todo->id}/assign", ['responsible_user_id' => $colleague->id, 'message' => 'Message privé pour Paul'])->assertSessionHasNoErrors();

    $this->getJson("/crm/organisations/{$todo->organisation_id}/timeline")->assertOk()
        ->assertJsonFragment(['title' => "Action confiée à {$colleague->name} par {$me->name}"]);

    $logs = AuditLog::query()->where('auditable_type', 'activity')->where('auditable_id', $todo->id)->get();
    expect($logs->pluck('event')->all())->toContain('crm.activity_assigned')
        ->and(json_encode($logs->map->only(['old_values', 'new_values'])->all()))->not->toContain('Message privé')
        ->and(json_encode($logs->map->only(['old_values', 'new_values'])->all()))->not->toContain('Rappeler le directeur');
});

it('announces the change to calendars once committed (Outlook follows)', function () {
    $me = commercial();
    $colleague = commercial();
    $this->actingAs($me);
    $todo = asTodo(['responsible_user_id' => $me->id]);
    Event::fake([ActivityChanged::class]);

    DB::transaction(function () use ($todo, $colleague) {
        $this->put("/crm/activities/{$todo->id}/assign", ['responsible_user_id' => $colleague->id])->assertSessionHasNoErrors();
        Event::assertNotDispatched(ActivityChanged::class);
    });

    Event::assertDispatched(ActivityChanged::class, fn (ActivityChanged $e) => $e->activityId === $todo->id
        && $e->responsibleUserId === $colleague->id && $e->previousResponsibleUserId === $me->id);
});

it('lists the activity_assigned template in « Modèles d’e-mails », editable', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->get('/admin/mail-templates')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('templates', fn ($t) => collect($t)->firstWhere('key', 'activity_assigned')['editable'] === true));
    $this->put('/admin/mail-templates/activity_assigned', ['subject' => 'Pour toi : {objet}', 'body' => "{auteur} te confie {objet}.\n{lien}"])
        ->assertSessionHasNoErrors();

    $me = commercial();
    $colleague = commercial();
    $this->actingAs($me);
    $todo = asTodo(['responsible_user_id' => $me->id]);
    $this->put("/crm/activities/{$todo->id}/assign", ['responsible_user_id' => $colleague->id])->assertSessionHasNoErrors();

    Mail::assertSent(ActivityAssignedMail::class, fn (ActivityAssignedMail $m) => $m->render() !== '' && str_contains($m->render(), "{$me->name} te confie Rappeler le directeur."));
});
