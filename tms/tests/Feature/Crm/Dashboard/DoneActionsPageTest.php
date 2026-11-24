<?php

use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicators;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'Europe/Paris'));
});

/** A user seeing only his own actions, with the dashboards right. */
function daUser(): User
{
    $user = userWithPermissions([Permissions::DASHBOARDS_VIEW, Permissions::ACTIVITIES_VIEW]);
    DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $user->roles()->firstOrFail()->id, 'permission' => Permissions::ACTIVITIES_VIEW, 'scope' => 'own']);
    Scopes::forget();

    return $user;
}

function daAction(string $type, User $responsible, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, $type)->id, 'subject' => $type,
        'organisation_id' => Organisation::query()->firstOrCreate(['name' => 'Hôtel du Parc'])->id,
        'responsible_user_id' => $responsible->id, ...$attributes,
    ]);
}

it('links the actions done and meetings done cards to the list of done actions, with the dashboard filters', function () {
    $month = DashboardFilters::from(['target' => 'organisation'], CarbonImmutable::now(), 'month');
    $week = DashboardFilters::from([], CarbonImmutable::now(), 'week');

    $done = Indicators::get('crm.actions_done_month')?->detailUrl($month);
    expect($done)->toStartWith('/crm/dashboards/actions?')
        ->and($done)->toContain('kind=done')->toContain('tab=month')->toContain('preset=this_month')->toContain('target=organisation')
        ->and(Indicators::get('crm.meetings_done_month')?->detailUrl($month))->toContain('kind=meetings')
        ->and(Indicators::get('crm.meetings_done')?->detailUrl($week))->toContain('kind=meetings')->toContain('tab=week');
});

it('lists the actions done in the period, as many as the card counts', function () {
    $me = daUser();
    daAction('Appel', $me, ['done_at' => '2026-11-03 09:10']);
    daAction('E-mail', $me, ['done_at' => '2026-11-12 14:00']);
    daAction('Rendez-vous physique', $me, ['done_at' => '2026-11-17 11:00']);
    daAction('Appel', $me, ['due_at' => '2026-11-25 09:00']); // not done
    daAction('Appel', $me, ['done_at' => '2026-10-28 09:00']); // last month
    daAction('Appel', commercial(), ['done_at' => '2026-11-05 09:00']); // not his

    $this->actingAs($me)->get('/crm/dashboards/actions?kind=done&tab=month&preset=this_month')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('crm/dashboards/actions')
            ->where('title', 'Actions faites')
            ->where('period', 'du 01/11/2026 au 30/11/2026')
            ->has('actions.data', 3)
            ->where('actions.data.0.subject', 'Rendez-vous physique')
            ->where('actions.total', 3));

    $this->actingAs($me)->get('/crm/dashboards/actions?kind=meetings&tab=month&preset=this_month')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('title', 'Rendez-vous faits')->has('actions.data', 1));
});

it('needs the dashboards and actions rights', function () {
    $this->actingAs(userWithPermissions([Permissions::ACTIVITIES_VIEW]))->get('/crm/dashboards/actions?kind=done')->assertForbidden();
    $this->actingAs(userWithPermissions([Permissions::DASHBOARDS_VIEW]))->get('/crm/dashboards/actions?kind=done')->assertForbidden();
});
