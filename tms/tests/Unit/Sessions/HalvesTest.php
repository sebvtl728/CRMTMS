<?php

use App\Modules\Sessions\Attendance\Signing\Halves;

it('gives the half-days a séance covers, cut at 13:00', function () {
    expect(Halves::of('09:00', '12:30'))->toBe(['morning'])
        ->and(Halves::of('13:00', '17:00'))->toBe(['afternoon'])
        ->and(Halves::of('09:00', '17:00'))->toBe(['morning', 'afternoon'])
        ->and(Halves::of('12:00', '13:00'))->toBe(['morning'])
        ->and(Halves::of('09:00:00', '17:00:00'))->toBe(['morning', 'afternoon']);
});

it('prorates the minutes of the signed half-days on the clock span', function () {
    expect(Halves::minutes(420, '09:00', '17:00', ['morning']))->toBe(210)
        ->and(Halves::minutes(420, '09:00', '17:00', ['afternoon']))->toBe(210)
        ->and(Halves::minutes(420, '09:00', '17:00', ['morning', 'afternoon']))->toBe(420)
        ->and(Halves::minutes(180, '09:00', '12:00', []))->toBe(0)
        ->and(Halves::minutes(300, '10:00', '16:00', ['morning']))->toBe(150);
});
