<?php

use App\Modules\Catalogue\Training;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * A user of a custom role holding exactly $grants (permission => scope; null: no scope row, « all »).
 *
 * @param  array<string, AccessScope|null>  $grants
 */
function sessVisUser(array $grants): User
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
 * A session of a training of $das, created by $creator, with $responsible.
 */
function sessVisSession(string $das, ?User $creator, ?User $responsible, string $starts = '2026-12-07'): TrainingSession
{
    $training = Training::query()->create(['title' => "Formation {$das}", 'das_id' => crmItem(ReferenceList::Das, $das)->id]);
    $session = app(SessionWriter::class)->create([
        'training_id' => $training->id, 'kind' => 'inter', 'starts_on' => $starts, 'ends_on' => $starts,
        'location_kind' => 'remote', 'capacity' => 5, 'status' => 'planned',
        'responsible_user_id' => $responsible?->id,
    ]);
    DB::table('training_sessions')->where('id', $session->id)->update(['created_by' => $creator?->id, 'responsible_user_id' => $responsible?->id]);

    return $session->fresh();
}

/**
 * @return array{mine: TrainingSession, responsible: TrainingSession, das: TrainingSession, other: TrainingSession}
 */
function sessVisFixture(User $me): array
{
    $someone = commercial();

    return [
        'mine' => sessVisSession('Métiers supports', $me, $someone),
        'responsible' => sessVisSession('Métiers supports', $someone, $me),
        'das' => sessVisSession('Hôtellerie-restauration', $someone, $someone),
        'other' => sessVisSession('Métiers supports', $someone, $someone),
    ];
}

/**
 * @return list<string>
 */
function sessVisListed($test): array
{
    $ids = collect($test->get('/sessions')->original->getData()['page']['props']['sessions']['data'])->pluck('id')->all();
    sort($ids);

    return $ids;
}

/**
 * @return list<string>
 */
function sessVisCalendar($test): array
{
    $ids = collect($test->get('/sessions/calendar?month=2026-12')->original->getData()['page']['props']['sessions'])->pluck('id')->all();
    sort($ids);

    return $ids;
}

/**
 * @param  list<TrainingSession>  $sessions
 * @return list<string>
 */
function sessVisIds(array $sessions): array
{
    $ids = array_map(fn (TrainingSession $s) => $s->id, $sessions);
    sort($ids);

    return $ids;
}

it('limits « Ses fiches » to the sessions he is responsible for or created', function () {
    $me = sessVisUser([Permissions::SESSIONS_VIEW => AccessScope::Own]);
    $s = sessVisFixture($me);
    $this->actingAs($me);

    expect(sessVisListed($this))->toBe(sessVisIds([$s['mine'], $s['responsible']]))
        ->and(sessVisCalendar($this))->toBe(sessVisIds([$s['mine'], $s['responsible']]));
    $this->get("/sessions/{$s['mine']->id}")->assertOk();
    $this->get("/sessions/{$s['other']->id}")->assertNotFound();
    $this->get("/sessions/{$s['das']->id}")->assertNotFound();
});

it('limits « Ses DAS » to the sessions of the DAS he follows', function () {
    $me = sessVisUser([Permissions::SESSIONS_VIEW => AccessScope::Das]);
    followDas($me, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    $s = sessVisFixture($me);
    $this->actingAs($me);

    expect(sessVisListed($this))->toBe(sessVisIds([$s['das']]))
        ->and(sessVisCalendar($this))->toBe(sessVisIds([$s['das']]));
    $this->get("/sessions/{$s['das']->id}")->assertOk();
    $this->get("/sessions/{$s['mine']->id}")->assertNotFound();
});

it('shows every session with « Tout l\'organisme » and nothing without the right', function () {
    $me = sessVisUser([Permissions::SESSIONS_VIEW => null]);
    $s = sessVisFixture($me);
    $this->actingAs($me);

    expect(sessVisListed($this))->toBe(sessVisIds(array_values($s)))
        ->and(sessVisCalendar($this))->toBe(sessVisIds(array_values($s)));

    $nobody = sessVisUser([App\Modules\Crm\Permissions::FICHES_VIEW => null]);
    $this->actingAs($nobody);
    $this->get('/sessions')->assertForbidden();
    $this->get('/sessions/calendar')->assertForbidden();
    expect(SessionsVisibility::sessions(TrainingSession::query(), $nobody)->count())->toBe(0);
});

it('gives the default Responsable DAS the sessions of his DAS', function () {
    $me = userWithRole(Role::ResponsableDas);
    // The default scopes of a system role are written by the permission sync.
    $role = $me->roles()->firstOrFail();
    DB::table(Scopes::TABLE)->where('role_id', $role->id)->where('permission', Permissions::SESSIONS_VIEW)->delete();
    DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => Permissions::SESSIONS_VIEW, 'scope' => 'das']);
    Scopes::forget();
    followDas($me, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    $s = sessVisFixture($me);

    expect(SessionsVisibility::sessions(TrainingSession::query(), $me)->pluck('id')->all())->toBe([$s['das']->id]);
});

it('uses the scope of the update and delete rights for changes', function () {
    $me = sessVisUser([
        Permissions::SESSIONS_VIEW => null,
        Permissions::SESSIONS_UPDATE => AccessScope::Own,
        Permissions::SESSIONS_DELETE => AccessScope::Own,
    ]);
    $s = sessVisFixture($me);
    $this->actingAs($me);

    // Visible but not his: read only.
    $this->get("/sessions/{$s['other']->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can.update', false)->where('can.delete', false));
    $this->get("/sessions/{$s['other']->id}/edit")->assertForbidden();
    $this->put("/sessions/{$s['other']->id}", [])->assertForbidden();
    $this->post("/sessions/{$s['other']->id}/cancel", ['cancel_reason_id' => crmItem(ReferenceList::CancelReason, 'Report')->id])->assertForbidden();
    $this->delete("/sessions/{$s['other']->id}")->assertForbidden();

    // His own: changes allowed.
    $this->get("/sessions/{$s['mine']->id}")->assertInertia(fn (Assert $page) => $page->where('can.update', true)->where('can.delete', true));
    $this->post("/sessions/{$s['mine']->id}/cancel", ['cancel_reason_id' => crmItem(ReferenceList::CancelReason, 'Report')->id])->assertRedirect();
    $this->delete("/sessions/{$s['responsible']->id}")->assertRedirect('/sessions');
});

it('refuses a DAS-limited user a session of a training outside his DAS', function () {
    $me = sessVisUser([Permissions::SESSIONS_VIEW => AccessScope::Das, Permissions::SESSIONS_UPDATE => AccessScope::Das]);
    followDas($me, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    $outside = Training::query()->create(['title' => 'Bureautique', 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);
    $inside = Training::query()->create(['title' => 'HACCP', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);
    $this->actingAs($me);

    $payload = ['kind' => 'inter', 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-08', 'location_kind' => 'remote', 'capacity' => 5, 'status' => 'planned'];
    $this->post('/sessions', ['training_id' => $outside->id, ...$payload])
        ->assertSessionHasErrors(['training_id' => 'Cette formation n\'est pas dans vos DAS.']);
    $this->post('/sessions', ['training_id' => $inside->id, ...$payload])->assertSessionHasNoErrors()->assertRedirect();

    $session = TrainingSession::query()->sole();
    $this->put("/sessions/{$session->id}", ['training_id' => $outside->id, ...$payload])
        ->assertSessionHasErrors(['training_id' => 'Cette formation n\'est pas dans vos DAS.']);
    expect($session->fresh()->training_id)->toBe($inside->id);
});
