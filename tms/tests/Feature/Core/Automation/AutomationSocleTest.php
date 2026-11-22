<?php

use App\Modules\Core\Automation\AutomationSends;
use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Core\Automation\DailyAutomations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    tenant();
    DailyAutomations::flush();
});

afterEach(fn () => DailyAutomations::flush());

it('claims an automatic send once', function () {
    $id = (string) Str::uuid7();

    expect(AutomationSends::claim(AutomationSends::CONVOCATION, $id, '2026-12-01'))->toBeTrue()
        ->and(AutomationSends::claim(AutomationSends::CONVOCATION, $id, '2026-12-01'))->toBeFalse()
        ->and(AutomationSends::claim(AutomationSends::CONVOCATION, $id, '2026-12-02'))->toBeTrue()
        ->and(AutomationSends::claim(AutomationSends::DAY_BEFORE, $id, '2026-12-01'))->toBeTrue();
});

it('reads the automation settings with their defaults and bounds', function () {
    expect(AutomationSettings::convocationDays())->toBe(7)
        ->and(AutomationSettings::dayBefore())->toBeTrue()
        ->and(AutomationSettings::signatureReminderDays())->toBe(3)
        ->and(AutomationSettings::signatureReminderMax())->toBe(2);

    expect(fn () => AutomationSettings::save(['convocation_days' => 45]))->toThrow(ValidationException::class, '30 jours au plus.');

    AutomationSettings::save(['convocation_days' => 0, 'day_before' => false, 'signature_reminder_days' => 5, 'signature_reminder_max' => 1]);
    expect(AutomationSettings::convocationDays())->toBe(0)
        ->and(AutomationSettings::dayBefore())->toBeFalse()
        ->and(AutomationSettings::signatureReminderDays())->toBe(5)
        ->and(AutomationSettings::signatureReminderMax())->toBe(1);
});

it('runs every daily automation and survives one failing', function () {
    DailyAutomations::register('broken', fn () => throw new RuntimeException('boom'));
    DailyAutomations::register('fine', fn (CarbonImmutable $today) => 3);

    expect(DailyAutomations::runAll(CarbonImmutable::now('Europe/Paris')))->toBe(['broken' => null, 'fine' => 3]);
});
