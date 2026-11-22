<?php

namespace App\Modules\Questionnaires\Results;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Questionnaires\Surveys\SurveyResponse;
use App\Modules\Questionnaires\Surveys\SurveyScoring;
use App\Modules\Sessions\Public\Surveys;
use Carbon\CarbonImmutable;

/**
 * The « Questionnaires » block of a session (Sessions\Public\SessionBlocks)
 * and its « Satisfaction » summary card (SessionCards): who sees the session
 * reads the results; who may update it launches the questionnaires.
 */
final class SessionSurveysBlock
{
    /** @var array<string, string> */
    public const LABELS = [
        'hot.trainee' => 'À chaud — stagiaires',
        'cold.trainee' => 'À froid — stagiaires',
        'hot.client' => 'À chaud — entreprise',
        'cold.client' => 'À froid — entreprise',
    ];

    /**
     * @return array{title: string, component: string, props: array<string, mixed>}|null
     */
    public static function for(string $sessionId, User $viewer): ?array
    {
        $session = Surveys::session($sessionId);
        if ($session === null || ! Surveys::allows($viewer, $sessionId, false)) {
            return null;
        }
        $campaigns = SurveyCampaign::query()->where('training_session_id', $sessionId)
            ->orderByRaw("case kind when 'hot' then 0 else 1 end")->orderBy('audience', 'desc')->get();
        $invitations = SurveyInvitation::query()->whereIn('campaign_id', $campaigns->pluck('id'))->orderBy('created_at')->get();
        $names = app(Fiches::class)->personLabels($invitations->pluck('person_id')->unique()->values()->all());

        return [
            'title' => 'Questionnaires',
            'component' => 'questionnaires.session',
            'props' => [
                'session_id' => $sessionId,
                'can_launch' => ! $session['cancelled'] && Surveys::allows($viewer, $sessionId, true),
                // The trainees a launch e-mails (confirmation of the button).
                'trainee_count' => count(Surveys::trainees($sessionId)),
                'ended' => $session['ends_on'] <= CarbonImmutable::now('Europe/Paris')->toDateString(),
                'qr_url' => $campaigns->contains(fn (SurveyCampaign $c) => $c->qr_token_hash !== null) ? "/sessions/{$sessionId}/questionnaire-qr" : null,
                'campaigns' => $campaigns->map(fn (SurveyCampaign $c) => [
                    'id' => $c->id,
                    'kind' => $c->kind,
                    'audience' => $c->audience,
                    'label' => self::LABELS["{$c->kind}.{$c->audience}"] ?? $c->title,
                    'opened_on' => $c->opened_at->setTimezone('Europe/Paris')->format('d/m/Y'),
                    'closes_on' => $c->closes_on->format('d/m/Y'),
                    'open' => $c->isOpen(),
                    'results' => SurveyResults::campaign($c),
                    'invitations' => $invitations->where('campaign_id', $c->id)->map(fn (SurveyInvitation $i) => [
                        'name' => $names[$i->person_id]['label'] ?? 'Personne',
                        'email_status' => $i->email_status,
                        'email_error' => $i->email_error,
                        'answered' => $i->answered_at !== null,
                    ])->sortBy('name')->values()->all(),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * « Satisfaction » (sessions having followed trainees): n/a before the last day; to do without answers to the
     * hot questionnaire of the trainees; done with at least one (mean score
     * and answers).
     *
     * @param  list<string>  $sessionIds
     * @return array<string, list<array{key: string, title: string, value: string, state: string, text: string, step: string, anchor: ?string}>>
     */
    public static function cards(array $sessionIds, User $viewer): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $today = CarbonImmutable::now('Europe/Paris')->toDateString();
        $campaigns = SurveyCampaign::query()->whereIn('training_session_id', $sessionIds)
            ->where('kind', SurveyCampaign::HOT)->where('audience', SurveyCampaign::TRAINEE)->get()->keyBy('training_session_id');
        $counts = SurveyInvitation::query()->whereIn('campaign_id', $campaigns->pluck('id'))
            ->groupBy('campaign_id')->selectRaw('campaign_id, count(*) as invited, count(answered_at) as answered')->get()->keyBy('campaign_id');
        $scores = SurveyResponse::query()->join('survey_invitations', 'survey_invitations.id', '=', 'survey_responses.invitation_id')
            ->whereIn('survey_invitations.campaign_id', $campaigns->pluck('id'))->whereNotNull('survey_responses.score')
            ->get(['survey_invitations.campaign_id', 'survey_responses.score'])->groupBy('campaign_id');

        $ends = Surveys::endDays($sessionIds);
        $trainees = Surveys::traineeCounts($sessionIds);
        $out = [];
        foreach ($sessionIds as $sessionId) {
            $endsOn = $ends[$sessionId] ?? null;
            // Nobody to ask: no card (as the documents' cards).
            if ($endsOn === null || ($trainees[$sessionId] ?? 0) === 0) {
                continue;
            }
            $campaign = $campaigns[$sessionId] ?? null;
            $invited = (int) ($campaign !== null ? ($counts[$campaign->id]->invited ?? 0) : 0);
            $answered = (int) ($campaign !== null ? ($counts[$campaign->id]->answered ?? 0) : 0);
            $card = ['key' => 'questionnaires.satisfaction', 'title' => 'Satisfaction', 'step' => 'stagiaires', 'anchor' => 'questionnaires'];
            if ($campaign === null) {
                $out[$sessionId] = [[...$card, 'value' => '-',
                    'state' => $endsOn > $today ? 'na' : 'todo',
                    'text' => $endsOn > $today ? 'Questionnaire à chaud à lancer le dernier jour.' : 'Questionnaire à chaud à lancer.']];

                continue;
            }
            $average = SurveyResults::mean(($scores[$campaign->id] ?? collect())->pluck('score')->map(fn ($s) => (int) $s)->all());
            $value = "{$answered} / {$invited} réponses";
            $out[$sessionId] = [[...$card,
                'value' => $answered > 0 ? SurveyScoring::label($average)." · {$value}" : $value,
                'state' => $answered > 0 ? 'done' : 'todo',
                'text' => $answered > 0 ? 'Satisfaction moyenne des stagiaires (questionnaire à chaud).' : 'En attente des réponses des stagiaires.']];
        }

        return $out;
    }
}
