<?php

namespace App\Modules\Questionnaires\Campaigns;

use App\Modules\Questionnaires\Settings\ColdDelay;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Sessions\Public\Surveys;
use Carbon\CarbonImmutable;

/**
 * The hot questionnaire leaves on the last day at 13:30 (runLastDay).
 * Every morning, in one organisme: the hot questionnaire of the sessions
 * ended since yesterday that nobody launched (catching up), and the cold one of the
 * sessions ended ColdDelay::months() months ago. A missed day is caught up
 * within CATCH_UP_DAYS, never later (no burst when the module starts); a
 * session gets each questionnaire once (one campaign per session and type).
 */
final class DailySurveys
{
    public const CATCH_UP_DAYS = 30;

    public function __construct(private readonly SurveyLaunch $launch) {}

    /** @return array{hot: int, cold: int} the campaigns launched */
    public function run(CarbonImmutable $today): array
    {
        $today = CarbonImmutable::parse($today->setTimezone('Europe/Paris')->toDateString(), 'Europe/Paris');
        $hot = $this->launchAll(SurveyCampaign::HOT, $today->subDays(self::CATCH_UP_DAYS), $today->subDay());
        $cold = 0;
        $months = ColdDelay::months();
        if ($months > 0) {
            $cold = $this->launchAll(SurveyCampaign::COLD, $today->subDays(self::CATCH_UP_DAYS)->subMonthsNoOverflow($months), $today->subMonthsNoOverflow($months));
        }

        return ['hot' => $hot, 'cold' => $cold];
    }

    /**
     * The last day of a session, at 13:30 (tms:automation:last-day): its hot
     * questionnaire, unless launched already; the next morning only
     * catches up the ones missed.
     */
    public function runLastDay(CarbonImmutable $now): int
    {
        $today = CarbonImmutable::parse($now->setTimezone('Europe/Paris')->toDateString(), 'Europe/Paris');

        return $this->launchAll(SurveyCampaign::HOT, $today, $today);
    }

    private function launchAll(string $kind, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $ids = Surveys::endedOn($from->toDateString(), $to->toDateString());
        $done = SurveyCampaign::query()->whereIn('training_session_id', $ids)->where('kind', $kind)
            ->where('audience', SurveyCampaign::TRAINEE)->pluck('training_session_id')->all();
        $launched = 0;
        foreach (array_diff($ids, $done) as $sessionId) {
            if ($this->launch->launch($sessionId, $kind, null) !== null) {
                $launched++;
            }
        }

        return $launched;
    }
}
