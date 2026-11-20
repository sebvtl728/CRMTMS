<?php

use App\Modules\Crm\Dashboard\DashboardFilters;
use Carbon\CarbonImmutable;

beforeEach(fn () => inTenant());

function dfNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Paris'); // a Friday
}

it('reads the presets in days of Paris, and the previous period of the same kind', function (string $preset, string $from, string $to, string $prevFrom, string $prevTo) {
    $f = DashboardFilters::from(['preset' => $preset], dfNow(), 'week');

    expect([$f->from->toDateString(), $f->to->toDateString()])->toBe([$from, $to])
        ->and([$f->previous()->from->toDateString(), $f->previous()->to->toDateString()])->toBe([$prevFrom, $prevTo]);
})->with([
    'this week' => ['this_week', '2026-11-16', '2026-11-22', '2026-11-09', '2026-11-15'],
    'last week' => ['last_week', '2026-11-09', '2026-11-15', '2026-11-02', '2026-11-08'],
    'this month' => ['this_month', '2026-11-01', '2026-11-30', '2026-10-01', '2026-10-31'],
    'last month' => ['last_month', '2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30'],
    'this quarter' => ['this_quarter', '2026-10-01', '2026-12-31', '2026-07-01', '2026-09-30'],
    'this year' => ['this_year', '2026-01-01', '2026-12-31', '2025-01-01', '2025-12-31'],
]);

it('defaults to this week on the week tab and this month on the month tab', function () {
    expect(DashboardFilters::from([], dfNow(), 'week')->preset)->toBe('this_week')
        ->and(DashboardFilters::from([], dfNow(), 'month')->preset)->toBe('this_month')
        ->and(DashboardFilters::from(['preset' => 'nope'], dfNow(), 'month')->preset)->toBe('this_month');
});

it('takes free dates, the previous period being as long and just before', function () {
    $f = DashboardFilters::from(['preset' => 'custom', 'from' => '2026-11-03', 'to' => '2026-11-12'], dfNow(), 'week');

    expect([$f->from->toDateString(), $f->to->toDateString()])->toBe(['2026-11-03', '2026-11-12'])
        ->and([$f->previous()->from->toDateString(), $f->previous()->to->toDateString()])->toBe(['2026-10-24', '2026-11-02']);
});

it('refuses free dates in the wrong order or malformed, falling back on the preset of the tab', function () {
    $f = DashboardFilters::from(['preset' => 'custom', 'from' => '2026-11-12', 'to' => '2026-11-03'], dfNow(), 'week');
    expect($f->preset)->toBe('this_week');
    $g = DashboardFilters::from(['preset' => 'custom', 'from' => '12/11/2026', 'to' => '2026-11-13'], dfNow(), 'week');
    expect($g->preset)->toBe('this_week');
});

it('keeps only known targets and uuid filters', function () {
    $f = DashboardFilters::from(['target' => 'person', 'das' => 'x', 'responsible' => '01a1f70d-777d-71c1-a9b2-65ce4ab43536'], dfNow(), 'week');

    expect($f->target)->toBe('person')->and($f->das)->toBeNull()->and($f->responsible)->toBe('01a1f70d-777d-71c1-a9b2-65ce4ab43536')
        ->and(DashboardFilters::from(['target' => 'x'], dfNow(), 'week')->target)->toBeNull();
});
