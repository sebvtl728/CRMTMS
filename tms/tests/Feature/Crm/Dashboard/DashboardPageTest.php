<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicator;
use App\Modules\Crm\Dashboard\Indicators;
use App\Modules\Crm\Dashboard\IndicatorValue;
use App\Modules\Crm\Public\DashboardIndicators;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Paris'));
});

// The registry is static: the test cards never outlive their test.
afterEach(fn () => array_map(fn (string $k) => Indicators::forget($k), ['test.seen', 'test.hidden', 'test.empty', 'test.cached']));

/** A test indicator counting its calls, on the week tab, needing $permission. */
function dpIndicator(string $key, string $permission, ?int $value = 3): Indicator
{
    return new class($key, $permission, $value) implements Indicator
    {
        public static int $calls = 0;

        public function __construct(private string $k, private string $p, private ?int $v) {}

        public function key(): string
        {
            return $this->k;
        }

        public function tab(): string
        {
            return 'week';
        }

        public function label(): string
        {
            return 'Indicateur '.$this->k;
        }

        public function formula(): string
        {
            return 'Nombre de tests';
        }

        public function permission(): string
        {
            return $this->p;
        }

        public function value(DashboardFilters $f, User $u): IndicatorValue
        {
            self::$calls++;

            return new IndicatorValue($this->v, IndicatorValue::COUNT);
        }

        public function detailUrl(DashboardFilters $f): ?string
        {
            return '/crm/opportunities';
        }
    };
}

it('shows the cards of the tab the user may see, with the previous period and the difference', function () {
    DashboardIndicators::register(dpIndicator('test.seen', 'crm.opportunities.view'));
    DashboardIndicators::register(dpIndicator('test.hidden', 'crm.revenue.manage'));

    $this->actingAs(commercial())->get('/crm/dashboards?tab=week&preset=this_week')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('crm/dashboards')
            ->where('filters.preset', 'this_week')->where('filters.from', '2026-11-16')->where('filters.to', '2026-11-22')
            ->where('period.previous', 'du 09/11/2026 au 15/11/2026')
            ->where('cards', fn ($cards) => collect($cards)->pluck('key')->contains('test.seen') && ! collect($cards)->pluck('key')->contains('test.hidden'))
            ->where('cards', fn ($cards) => ($c = collect($cards)->firstWhere('key', 'test.seen'))['value'] === 3 && $c['previous'] === 3
                && $c['delta'] === 0 && $c['formula'] === 'Nombre de tests' && $c['detail_url'] === '/crm/opportunities'));
});

it('shows a card without data as null, never an error', function () {
    DashboardIndicators::register(dpIndicator('test.empty', 'crm.opportunities.view', null));

    $this->actingAs(commercial())->get('/crm/dashboards')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('cards', fn ($cards) => ($c = collect($cards)->firstWhere('key', 'test.empty'))['value'] === null && $c['delta'] === null));
});

it('caches each card 60 s under the tenant, per user and filters', function () {
    $indicator = dpIndicator('test.cached', 'crm.opportunities.view');
    DashboardIndicators::register($indicator);
    $user = commercial();
    $indicator::$calls = 0;

    $this->actingAs($user)->get('/crm/dashboards')->assertOk();
    $this->get('/crm/dashboards')->assertOk();
    expect($indicator::$calls)->toBe(2); // current and previous periods, computed once

    $this->travel(61)->seconds();
    $this->get('/crm/dashboards')->assertOk();
    expect($indicator::$calls)->toBe(4)
        ->and(Cache::has('tenant:'.tenant()->id.':dashboard:'.$user->id.':'.DashboardFilters::from([], CarbonImmutable::now(), 'week')->key().':test.cached'))->toBeTrue();
});

it('is reached with any CRM view right, never without', function () {
    $this->actingAs(userWithPermissions(['crm.activities.view']))->get('/crm/dashboards')->assertOk();
    $this->actingAs(userWithPermissions(['sessions.sessions.view']))->get('/crm/dashboards')->assertForbidden();
});
