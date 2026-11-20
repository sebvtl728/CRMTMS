<?php

namespace App\Modules\Questionnaires\Results;

use App\Modules\Questionnaires\Results\SessionSurveysBlock as Labels;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Questionnaires\Surveys\SurveyResponse;
use App\Modules\Questionnaires\Surveys\SurveyScoring;
use App\Modules\Sessions\Public\Surveys;
use Carbon\CarbonImmutable;

/**
 * The results of every session (page Qualité › Questionnaires, export,
 * Qualiopi measures): one row per campaign whose session ended between two
 * Paris days (default: the last 12 months), filtered by type, public and
 * training; the totals weigh every invitation and every response alike.
 */
final class SurveyOverview
{
    /**
     * @param  array<string, mixed>  $filters  from, to (Y-m-d), kind, audience, training_id
     * @return list<array{campaign_id: string, session_id: string, session_label: string, training_id: string, ends_on: string, kind: string, audience: string, label: string, invited: int, answered: int, response_rate: ?int, average: ?int, satisfaction_rate: ?int, scores: list<int>}>
     */
    public static function rows(array $filters = []): array
    {
        [$from, $to] = self::period($filters);
        $campaigns = SurveyCampaign::query()
            ->when(in_array($filters['kind'] ?? null, ['hot', 'cold'], true), fn ($q) => $q->where('kind', $filters['kind']))
            ->when(in_array($filters['audience'] ?? null, ['trainee', 'client'], true), fn ($q) => $q->where('audience', $filters['audience']))
            ->get(['id', 'training_session_id', 'kind', 'audience']);
        $sessions = Surveys::describe($campaigns->pluck('training_session_id')->unique()->values()->all());
        $training = is_string($filters['training_id'] ?? null) && $filters['training_id'] !== '' ? $filters['training_id'] : null;
        $campaigns = $campaigns->filter(function (SurveyCampaign $c) use ($sessions, $from, $to, $training): bool {
            $s = $sessions[$c->training_session_id] ?? null;

            return $s !== null && $s['ends_on'] >= $from && $s['ends_on'] <= $to && ($training === null || $s['training_id'] === $training);
        });
        $ids = $campaigns->pluck('id')->all();
        $counts = SurveyInvitation::query()->whereIn('campaign_id', $ids)->groupBy('campaign_id')
            ->selectRaw('campaign_id, count(*) as invited, count(answered_at) as answered')->toBase()->get()->keyBy('campaign_id');
        $scores = SurveyResponse::query()->join('survey_invitations', 'survey_invitations.id', '=', 'survey_responses.invitation_id')
            ->whereIn('survey_invitations.campaign_id', $ids)->whereNotNull('survey_responses.score')
            ->toBase()->get(['survey_invitations.campaign_id', 'survey_responses.score'])->groupBy('campaign_id');

        return $campaigns->map(function (SurveyCampaign $c) use ($sessions, $counts, $scores): array {
            $s = $sessions[$c->training_session_id];
            $invited = (int) ($counts[$c->id]->invited ?? 0);
            $answered = (int) ($counts[$c->id]->answered ?? 0);
            $list = ($scores[$c->id] ?? collect())->pluck('score')->map(fn ($v) => (int) $v)->values()->all();

            return [
                'campaign_id' => $c->id,
                'session_id' => $s['id'],
                'session_label' => $s['label'],
                'training_id' => $s['training_id'],
                'ends_on' => $s['ends_on'],
                'kind' => $c->kind,
                'audience' => $c->audience,
                'label' => Labels::LABELS["{$c->kind}.{$c->audience}"] ?? $c->kind,
                'invited' => $invited,
                'answered' => $answered,
                'response_rate' => SurveyResults::rate($answered, $invited),
                'average' => SurveyResults::mean($list),
                'satisfaction_rate' => SurveyResults::rate(count(array_filter($list, fn (int $v) => $v >= SurveyScoring::SATISFIED)), count($list)),
                'scores' => $list,
            ];
        })->sortBy([['ends_on', 'desc'], ['kind', 'desc'], ['audience', 'desc']])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{campaigns: int, invited: int, answered: int, response_rate: ?int, average: ?int, satisfaction_rate: ?int, satisfied: int, scored: int}
     */
    public static function totals(array $rows): array
    {
        $invited = array_sum(array_column($rows, 'invited'));
        $answered = array_sum(array_column($rows, 'answered'));
        $scores = array_merge([], ...array_column($rows, 'scores'));
        $satisfied = count(array_filter($scores, fn (int $v) => $v >= SurveyScoring::SATISFIED));

        return [
            'campaigns' => count($rows),
            'invited' => $invited,
            'answered' => $answered,
            'response_rate' => SurveyResults::rate($answered, $invited),
            'average' => SurveyResults::mean($scores),
            'satisfaction_rate' => SurveyResults::rate($satisfied, count($scores)),
            'satisfied' => $satisfied,
            'scored' => count($scores),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: string}
     */
    public static function period(array $filters): array
    {
        $today = CarbonImmutable::now('Europe/Paris');
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false ? $v : null;

        return [$date($filters['from'] ?? null) ?? $today->subYear()->toDateString(), $date($filters['to'] ?? null) ?? $today->toDateString()];
    }
}
