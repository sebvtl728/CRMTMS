<?php

use App\Modules\Crm\Revenue\Period;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

it('reads a quarter, a month and a year', function () {
    $quarter = Period::of('quarter', '2026-T4');
    expect($quarter->type)->toBe('quarter')
        ->and($quarter->start->toDateString())->toBe('2026-10-01')
        ->and($quarter->end->toDateString())->toBe('2026-12-31')
        ->and($quarter->key())->toBe('2026-T4');

    $month = Period::of('month', '2026-02');
    expect($month->start->toDateString())->toBe('2026-02-01')->and($month->end->toDateString())->toBe('2026-02-28');

    $year = Period::of('year', '2026');
    expect($year->start->toDateString())->toBe('2026-01-01')->and($year->end->toDateString())->toBe('2026-12-31');
});

it('refuses an invalid key with a French validation error', function (string $type, string $key) {
    try {
        Period::of($type, $key);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(422)->and($e->errors()['period'][0])->toContain('période');
    }
})->with([
    ['month', '2026-13'], ['month', '2026-T4'], ['quarter', '2026-T5'], ['quarter', '2026-4'],
    ['year', '26'], ['year', 'abcd'], ['week', '2026'], ['month', ''],
]);

it('labels in French', function () {
    expect(Period::of('month', '2026-11')->label())->toBe('novembre 2026')
        ->and(Period::of('month', '2026-08')->label())->toBe('août 2026')
        ->and(Period::of('quarter', '2026-T4')->label())->toBe('4e trimestre 2026')
        ->and(Period::of('quarter', '2026-T1')->label())->toBe('1er trimestre 2026')
        ->and(Period::of('year', '2026')->label())->toBe('2026');
});

it('finds the current period in Paris days', function () {
    $now = CarbonImmutable::parse('2026-11-15 10:00', 'Europe/Paris');
    expect(Period::current('quarter', $now)->key())->toBe('2026-T4')
        ->and(Period::current('month', $now)->key())->toBe('2026-11')
        ->and(Period::current('year', $now)->key())->toBe('2026');

    // 23:30 on 31 October in UTC is already 1 November in Paris (winter time: 00:30).
    $utc = CarbonImmutable::parse('2026-10-31 23:30', 'UTC');
    expect(Period::current('month', $utc)->key())->toBe('2026-11');
});

it('goes to the previous period', function () {
    expect(Period::of('month', '2026-01')->previous()->key())->toBe('2025-12')
        ->and(Period::of('quarter', '2026-T1')->previous()->key())->toBe('2025-T4')
        ->and(Period::of('quarter', '2026-T4')->previous()->key())->toBe('2026-T3')
        ->and(Period::of('year', '2026')->previous()->key())->toBe('2025');
});
