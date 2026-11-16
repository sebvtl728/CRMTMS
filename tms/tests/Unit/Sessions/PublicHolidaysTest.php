<?php

use App\Modules\Sessions\Planning\PublicHolidays;
use App\Modules\Sessions\Planning\WorkingDays;

it('lists the 11 French national public holidays of 2026, Whit Monday included', function () {
    expect(PublicHolidays::for(2026))->toBe([
        '2026-01-01' => 'Jour de l\'an',
        '2026-04-06' => 'Lundi de Pâques',
        '2026-05-01' => 'Fête du Travail',
        '2026-05-08' => 'Victoire 1945',
        '2026-05-14' => 'Ascension',
        '2026-05-25' => 'Lundi de Pentecôte',
        '2026-07-14' => 'Fête nationale',
        '2026-08-15' => 'Assomption',
        '2026-11-01' => 'Toussaint',
        '2026-11-11' => 'Armistice 1918',
        '2026-12-25' => 'Noël',
    ]);
});

it('computes Easter locally, year by year', function (int $year, string $easterMonday, string $ascension, string $whitMonday) {
    $days = PublicHolidays::for($year);

    expect($days[$easterMonday] ?? null)->toBe('Lundi de Pâques')
        ->and($days[$ascension] ?? null)->toBe('Ascension')
        ->and($days[$whitMonday] ?? null)->toBe('Lundi de Pentecôte')
        ->and($days)->toHaveCount(11);
})->with([
    [2027, '2027-03-29', '2027-05-06', '2027-05-17'],
    [2025, '2025-04-21', '2025-05-29', '2025-06-09'],
    [2028, '2028-04-17', '2028-05-25', '2028-06-05'],
    [2038, '2038-04-26', '2038-06-03', '2038-06-14'],
]);

it('names a holiday and lists those of a range of days', function () {
    expect(PublicHolidays::label('2026-07-14'))->toBe('Fête nationale')
        ->and(PublicHolidays::label('2026-07-15'))->toBeNull()
        ->and(PublicHolidays::between('2026-12-20', '2027-01-05'))->toBe([
            '2026-12-25' => 'Noël',
            '2027-01-01' => 'Jour de l\'an',
        ]);
});

it('counts working days: Monday to Friday, without holidays', function () {
    expect(WorkingDays::between('2026-11-09', '2026-11-15', collect()))
        ->toBe(['2026-11-09', '2026-11-10', '2026-11-12', '2026-11-13']);
});
