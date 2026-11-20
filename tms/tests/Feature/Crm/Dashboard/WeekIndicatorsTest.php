<?php

use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicators;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'Europe/Paris')); // week of 16 to 22/11
});

/** Value of an indicator for $user over the given filters (week tab). */
function dashValue(string $key, User $user, array $input = []): int|float|null
{
    $indicator = Indicators::get($key) ?? throw new RuntimeException("No indicator {$key}");

    return $indicator->value(DashboardFilters::from($input, CarbonImmutable::now(), $indicator->tab()), $user)->value;
}

/** A user who sees his own records only (« Ses fiches ») for these rights. */
function dashOwnUser(array $permissions): User
{
    $user = userWithPermissions($permissions);
    $role = $user->roles()->firstOrFail();
    foreach ($permissions as $permission) {
        DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => $permission, 'scope' => 'own']);
    }
    Scopes::forget();

    return $user;
}

function wkAction(string $type, User $responsible, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, $type)->id, 'subject' => $type,
        'organisation_id' => Organisation::query()->firstOrCreate(['name' => 'Hôtel du Parc'])->id,
        'responsible_user_id' => $responsible->id, ...$attributes,
    ]);
}

it('counts the calls done, the meetings fixed and the response rate of the period, in the user scope', function () {
    $me = dashOwnUser([Permissions::ACTIVITIES_VIEW]);
    $other = commercial();
    $reached = crmItem(ReferenceList::ActionResult, 'Réussi')->id;
    $missed = crmItem(ReferenceList::ActionResult, 'Échec')->id;
    wkAction('Appel', $me, ['done_at' => '2026-11-17 10:00', 'result_id' => $reached]);
    wkAction('Appel', $me, ['done_at' => '2026-11-18 09:00', 'result_id' => $missed]);
    wkAction('Appel', $me, ['done_at' => '2026-11-18 09:30', 'result_id' => $reached]);
    wkAction('Appel', $me, ['done_at' => '2026-11-10 09:00', 'result_id' => $reached]); // last week
    wkAction('Appel', $me, ['due_at' => '2026-11-19 09:00']); // not done
    wkAction('Appel', $other, ['done_at' => '2026-11-17 11:00', 'result_id' => $reached]); // not his
    wkAction('Rendez-vous physique', $me, ['due_at' => '2026-12-01 10:00']); // fixed this week
    wkAction('E-mail', $me, ['done_at' => '2026-11-17 12:00']);

    expect(dashValue('crm.calls', $me))->toBe(3)
        ->and(dashValue('crm.calls', $me, ['preset' => 'last_week']))->toBe(1)
        ->and(dashValue('crm.meetings', $me))->toBe(1)
        ->and(dashValue('crm.response_rate', $me))->toBe(66.7)
        ->and(dashValue('crm.calls', commercial()))->toBe(4);

    // No result marked « joint »: no rate.
    DB::table('reference_items')->where('list', 'action_result')->update(['meta' => null]);
    expect(dashValue('crm.response_rate', $me))->toBeNull();
});

it('counts as a lead a fiche first reaching the threshold stage in the period', function () {
    $user = commercial();
    $stages = app(OpportunityStage::class);
    $parc = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $lac = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $mer = Organisation::query()->create(['name' => 'Hôtel de la Mer']);

    // Contacted this week (two opportunities: one lead).
    $a = makeOpportunity($parc);
    $stages->move($a, pipelineStage('Contacté'), null, null, null);
    $b = makeOpportunity($parc, ['stage_id' => pipelineStage('Étude')->id]);
    // Created directly beyond the threshold this week.
    makeOpportunity($lac, ['stage_id' => pipelineStage('Proposition')->id]);
    // Already a lead last week: not a new one.
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Europe/Paris'));
    makeOpportunity($mer, ['stage_id' => pipelineStage('Contacté')->id]);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'Europe/Paris'));
    makeOpportunity($mer, ['stage_id' => pipelineStage('Étude')->id]);
    // Still « Nouveau »: not a lead.
    makeOpportunity(Organisation::query()->create(['name' => 'Hôtel Neuf']));

    expect(dashValue('crm.leads', $user))->toBe(2)
        ->and(dashValue('crm.leads', $user, ['preset' => 'last_week']))->toBe(1)
        ->and($b->exists)->toBeTrue();
});

it('sums the weighted pipeline and counts the opportunities to close in the period', function () {
    $me = dashOwnUser([Permissions::OPPORTUNITIES_VIEW]);
    $parc = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    makeOpportunity($parc, ['amount' => '2000', 'probability' => 40, 'expected_close_on' => '2026-11-20', 'responsible_user_id' => $me->id]);
    makeOpportunity($parc, ['amount' => '1000', 'probability' => 50, 'expected_close_on' => '2026-11-22', 'responsible_user_id' => $me->id]);
    makeOpportunity($parc, ['amount' => '9000', 'probability' => 50, 'expected_close_on' => '2026-12-22', 'responsible_user_id' => $me->id]); // later
    makeOpportunity($parc, ['amount' => '5000', 'probability' => 50, 'expected_close_on' => '2026-11-20']); // not his

    expect(dashValue('crm.weighted_pipeline', $me))->toBe(130000)
        ->and(dashValue('crm.closing', $me))->toBe(2)
        ->and(dashValue('crm.closing', commercial()))->toBe(3)
        ->and(dashValue('crm.closing', commercial(), ['target' => 'person']))->toBe(0);
});

it('counts the quotes sent and accepted in the period', function () {
    $user = commercial();
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Hôtel du Parc']));
    makeQuote($opportunity)->forceFill(['status' => 'sent', 'sent_at' => '2026-11-17 10:00'])->save();
    makeQuote($opportunity)->forceFill(['status' => 'accepted', 'sent_at' => '2026-11-03 10:00', 'accepted_on' => '2026-11-18'])->save();
    makeQuote($opportunity); // draft

    expect(dashValue('crm.quotes_sent', $user))->toBe(1)
        ->and(dashValue('crm.quotes_accepted', $user))->toBe(1)
        ->and(dashValue('crm.quotes_sent', $user, ['preset' => 'this_month']))->toBe(2);
});
