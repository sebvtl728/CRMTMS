<?php

namespace App\Modules\Questionnaires\Results;

use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyResponse;
use App\Modules\Questionnaires\Surveys\SurveyScoring;

/**
 * The satisfaction of a trainee on his trainings followed (fiche, through
 * Sessions\Public\EnrolmentSatisfaction): the score of his answer to the hot
 * questionnaire of the session, « 4,5/5 ».
 */
final class SurveySatisfaction
{
    /**
     * @param  list<string>  $enrolmentIds
     * @return array<string, string>
     */
    public static function forEnrolments(array $enrolmentIds): array
    {
        return SurveyResponse::query()
            ->join('survey_invitations', 'survey_invitations.id', '=', 'survey_responses.invitation_id')
            ->join('survey_campaigns', 'survey_campaigns.id', '=', 'survey_invitations.campaign_id')
            ->whereIn('survey_invitations.enrolment_id', $enrolmentIds)
            ->where('survey_campaigns.kind', SurveyCampaign::HOT)->where('survey_campaigns.audience', SurveyCampaign::TRAINEE)
            ->whereNotNull('survey_responses.score')
            ->toBase()->get(['survey_invitations.enrolment_id', 'survey_responses.score'])
            ->mapWithKeys(fn (object $r) => [(string) $r->enrolment_id => SurveyScoring::label((int) $r->score)])->all();
    }
}
