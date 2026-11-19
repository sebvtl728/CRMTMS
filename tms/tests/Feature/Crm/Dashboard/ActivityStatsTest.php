<?php

use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Dashboard\ActivityStats;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

/** An action of $responsible created at $created (Paris), done at $done when given. */
function statAction(User $responsible, Organisation $org, string $type, string $created, ?string $done = null, array $collaborators = []): Activity
{
    $previous = auth()->user();
    test()->actingAs($responsible);
    $activity = Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, $type)->id,
        'subject' => $type,
        'organisation_id' => $org->id,
        'responsible_user_id' => $responsible->id,
        'due_at' => CarbonImmutable::parse('2026-12-10 10:00', 'Europe/Paris'),
        'done_at' => $done === null ? null : CarbonImmutable::parse($done, 'Europe/Paris'),
    ]);
    $previous === null ? auth()->logout() : test()->actingAs($previous);
    // created_at as if the action was recorded that day.
    DB::table('activities')->where('id', $activity->id)->update(['created_at' => CarbonImmutable::parse($created, 'Europe/Paris')]);

    return $activity;
}

/** A user who sees his own actions only (« Ses fiches »). */
function statOwnUser(): User
{
    $user = userWithPermissions([Permissions::ACTIVITIES_VIEW, Permissions::ACTIVITIES_UPDATE, Permissions::FICHES_VIEW]);
    $role = $user->roles()->firstOrFail();
    foreach ([Permissions::ACTIVITIES_VIEW, Permissions::FICHES_VIEW] as $permission) {
        DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => $permission, 'scope' => 'own']);
    }
    Scopes::forget();

    return $user;
}

it('counts the actions created and done and the meetings fixed in the paris month, within the scope', function () {
    $me = statOwnUser();
    $colleague = commercial();
    $this->actingAs($colleague);
    $other = Organisation::query()->create(['name' => 'Autre', 'responsible_user_id' => $colleague->id]);
    $this->actingAs($me);
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Lac', 'responsible_user_id' => $me->id]);

    statAction($me, $hotel, 'Appel', '2026-11-02 09:00', '2026-11-02 09:30');
    statAction($me, $hotel, 'Appel', '2026-10-31 23:30', '2026-11-01 00:10'); // created in October (Paris), done in November
    statAction($me, $hotel, 'Rendez-vous physique', '2026-11-18 11:00'); // a meeting fixed in November, held in December
    statAction($me, $hotel, 'Prise de rendez-vous', '2026-10-05 11:00');
    statAction($colleague, $other, 'Appel', '2026-11-03 09:00', '2026-11-03 10:00'); // not in my scope

    $stats = ActivityStats::for($me, CarbonImmutable::now());
    expect($stats['current'])->toBe(['month' => 'Novembre 2026', 'created' => 2, 'done' => 2, 'meetings' => 1])
        ->and($stats['previous'])->toBe(['month' => 'Octobre 2026', 'created' => 2, 'done' => 0, 'meetings' => 1])
        ->and($stats['months'])->toHaveCount(12)
        ->and($stats['months'][11]['key'])->toBe('2026-11')
        ->and($stats['months'][0]['key'])->toBe('2025-12');

    // Direction (scope « Tout ») sees the colleague's action too.
    expect(ActivityStats::for(direction(), CarbonImmutable::now())['current']['created'])->toBe(3);
});

it('shows the stats on the dashboard to who may see the actions, not the trashed fiches', function () {
    $me = commercial();
    $this->actingAs($me);
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Lac', 'responsible_user_id' => $me->id]);
    $gone = Organisation::query()->create(['name' => 'Corbeille', 'responsible_user_id' => $me->id]);
    statAction($me, $hotel, 'Appel', '2026-11-02 09:00');
    statAction($me, $gone, 'Appel', '2026-11-02 09:00');
    $gone->delete();

    $this->get('/dashboard')->assertInertia(fn ($p) => $p
        ->where('commercial.activity_stats.current.created', 1)
        ->has('commercial.activity_stats.months', 12));
});

it('never counts an action for a collaborator, only for its scope', function () {
    $me = statOwnUser();
    $colleague = commercial();
    $other = Organisation::query()->create(['name' => 'Autre', 'responsible_user_id' => $colleague->id]);
    $action = statAction($colleague, $other, 'Appel', '2026-11-03 09:00');
    DB::table('activity_collaborators')->insert(['tenant_id' => tenant()->id, 'activity_id' => $action->id, 'user_id' => $me->id]);

    expect(ActivityStats::for($me, CarbonImmutable::now())['current']['created'])->toBe(0);
});
