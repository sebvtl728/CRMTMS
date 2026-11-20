<?php

namespace App\Modules\Questionnaires\Answering;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyContent;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Questionnaires\Surveys\SurveyResponse;
use App\Modules\Questionnaires\Surveys\SurveyScoring;
use App\Modules\Sessions\Public\Surveys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Answering a questionnaire without an account: which invitation or campaign
 * a public link reaches (null = the same 404 for every invalid link), the
 * names shown on the session QR page, and the recording of the answers, once
 * per invitation (row lock).
 */
final class SurveyAnswers
{
    /** The invitation of a personal link: campaign open, session live. */
    public static function invitation(string $token): ?SurveyInvitation
    {
        $invitation = SurveyInvitation::findByToken($token);

        return $invitation !== null && self::live($invitation->campaign) ? $invitation : null;
    }

    /** The campaign of a session QR link: open, session live. */
    public static function campaign(string $token): ?SurveyCampaign
    {
        $campaign = SurveyCampaign::findByQrToken($token);

        return $campaign !== null && self::live($campaign) ? $campaign : null;
    }

    public static function live(SurveyCampaign $campaign): bool
    {
        $session = Surveys::session($campaign->training_session_id);

        return $campaign->isOpen() && $session !== null && ! $session['cancelled'];
    }

    /**
     * The trainees of the QR page who have not answered: « Léa D. », by name.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function waiting(SurveyCampaign $campaign): array
    {
        $invitations = $campaign->invitations()->whereNull('answered_at')->get(['id', 'person_id']);
        $people = app(Fiches::class)->personLabels($invitations->pluck('person_id')->all());

        return $invitations->map(function (SurveyInvitation $i) use ($people): array {
            $p = $people[$i->person_id] ?? null;
            $initial = mb_strtoupper(mb_substr((string) ($p['last_name'] ?? ''), 0, 1));
            $name = trim((string) ($p['first_name'] ?? '')) !== '' ? trim($p['first_name'].' '.$initial.($initial !== '' ? '.' : '')) : (string) ($p['last_name'] ?? 'Stagiaire');

            return ['id' => $i->id, 'name' => $name];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * @param  array<string, mixed>|mixed  $answers
     */
    public function submit(SurveyInvitation $invitation, mixed $answers, string $via): SurveyResponse
    {
        $values = SurveyContent::validateAnswers($invitation->campaign->questions, $answers);

        return DB::transaction(function () use ($invitation, $values, $via): SurveyResponse {
            /** @var SurveyInvitation|null $locked */
            $locked = SurveyInvitation::query()->lockForUpdate()->find($invitation->id);
            if ($locked === null || ! self::live($locked->campaign)) {
                abort(404);
            }
            if ($locked->answered_at !== null) {
                throw new UserFacingHttpException(409, 'Vous avez déjà répondu à ce questionnaire.');
            }
            $now = CarbonImmutable::now();
            $response = SurveyResponse::query()->create([
                'invitation_id' => $locked->id,
                'answers' => $values,
                'score' => SurveyScoring::score($locked->campaign->questions, $values),
                'submitted_at' => $now,
            ]);
            $locked->forceFill(['answered_at' => $now, 'answered_via' => $via])->save();

            return $response;
        });
    }
}
