<?php

use App\Modules\Core\References\ReferenceList;
use App\Modules\Questionnaires\Campaigns\DailySurveys;
use App\Modules\Questionnaires\Campaigns\SurveyLaunch;
use App\Modules\Questionnaires\Settings\ColdDelay;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyTemplate;
use App\Modules\Questionnaires\Templates\DefaultTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

require_once __DIR__.'/Support/surveys.php';

beforeEach(function () {
    svSetUp('2026-12-01 07:00');
    Queue::fake();
});

function svRun(): array
{
    return app(DailySurveys::class)->run(CarbonImmutable::now('Europe/Paris'));
}

it('launches the hot questionnaire the day after the end, never twice nor after a manual launch', function () {
    [$yesterday] = svSession('2026-11-30');
    [$manual] = svSession('2026-11-29');
    app(SurveyLaunch::class)->launch($manual->id, 'hot', admin());
    [$old] = svSession('2026-10-31');
    [$today] = svSession('2026-12-01');

    expect(svRun())->toBe(['hot' => 1, 'cold' => 0])
        ->and(svRun())->toBe(['hot' => 0, 'cold' => 0])
        ->and(SurveyCampaign::query()->where('training_session_id', $yesterday->id)->where('kind', 'hot')->first()?->launched_by)->toBeNull()
        ->and(SurveyCampaign::query()->whereIn('training_session_id', [$old->id, $today->id])->count())->toBe(0)
        ->and(SurveyCampaign::query()->where('training_session_id', $manual->id)->count())->toBe(1);
});

it('sends the cold questionnaire N months after the end, within the catch-up window', function () {
    [$due] = svSession('2026-09-01');
    [$late] = svSession('2026-08-15');
    [$tooLate] = svSession('2026-07-31');
    SurveyCampaign::query()->delete();

    $result = svRun();

    expect($result['cold'])->toBe(2)
        ->and(SurveyCampaign::query()->where('kind', 'cold')->pluck('training_session_id')->sort()->values()->all())
        ->toBe(collect([$due->id, $late->id])->sort()->values()->all())
        ->and(SurveyCampaign::query()->where('training_session_id', $tooLate->id)->where('kind', 'cold')->exists())->toBeFalse();
});

it('sends no cold questionnaire with a delay of 0, nor without an active cold template, nor for a cancelled session', function () {
    [$due] = svSession('2026-09-01');
    ColdDelay::set(0);
    expect(svRun()['cold'])->toBe(0);

    ColdDelay::set(3);
    DefaultTemplates::ensure();
    SurveyTemplate::query()->where('kind', 'cold')->update(['active' => false]);
    expect(svRun()['cold'])->toBe(0);

    SurveyTemplate::query()->where('kind', 'cold')->update(['active' => true]);
    $due->forceFill(['status' => 'cancelled', 'cancel_reason_id' => crmItem(ReferenceList::CancelReason, 'Autre')->id, 'cancelled_on' => '2026-08-20'])->save();
    expect(svRun()['cold'])->toBe(0);
});

it('runs every morning at 7:00 Paris through tms:questionnaires:daily', function () {
    svSession('2026-11-30');

    $this->artisan('tms:questionnaires:daily')->assertSuccessful();

    expect(SurveyCampaign::query()->where('kind', 'hot')->count())->toBe(1);
    $event = collect(Schedule::events())->first(fn ($e) => str_contains((string) $e->command, 'tms:questionnaires:daily'));
    expect($event?->expression)->toBe('0 7 * * *')->and($event?->timezone)->toBe('Europe/Paris');
});

it('launches the hot questionnaire on the last day at 13:30, the next morning only catching up', function () {
    [$today] = svSession('2026-12-01');
    [$tomorrow] = svSession('2026-12-02');
    $this->travelTo(CarbonImmutable::parse('2026-12-01 13:30', 'Europe/Paris'));

    expect(app(DailySurveys::class)->runLastDay(CarbonImmutable::now('Europe/Paris')))->toBe(1)
        ->and(SurveyCampaign::query()->where('training_session_id', $today->id)->where('kind', 'hot')->exists())->toBeTrue()
        ->and(SurveyCampaign::query()->where('training_session_id', $tomorrow->id)->exists())->toBeFalse()
        ->and(app(DailySurveys::class)->runLastDay(CarbonImmutable::now('Europe/Paris')))->toBe(0);

    // The next morning: nothing left to launch for it.
    $this->travelTo(CarbonImmutable::parse('2026-12-02 07:00', 'Europe/Paris'));
    expect(svRun()['hot'])->toBe(0);
});

it('runs the automations of the last day at 13:30', function () {
    $event = collect(Schedule::events())->first(fn ($e) => str_contains($e->command ?? '', 'tms:automation:last-day'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('30 13 * * *');
});
