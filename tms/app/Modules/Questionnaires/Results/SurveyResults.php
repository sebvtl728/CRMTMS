<?php

namespace App\Modules\Questionnaires\Results;

use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyResponse;
use App\Modules\Questionnaires\Surveys\SurveyScoring;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The results of one campaign: invited, answered, response rate, mean score
 * (×100) and satisfaction rate (respondents scoring at least
 * SurveyScoring::SATISFIED), then per question: mean and distribution of
 * the marks, count of each option, free texts (newest first, never a name).
 */
final class SurveyResults
{
    /** A percentage rounded half up; null without a total. */
    public static function rate(int $part, int $total): ?int
    {
        return $total === 0 ? null : BigDecimal::of($part * 100)->dividedBy($total, 0, RoundingMode::HALF_UP)->toInt();
    }

    /**
     * The mean of integers, half up; null for none.
     *
     * @param  list<int>  $values
     */
    public static function mean(array $values): ?int
    {
        return $values === [] ? null : BigDecimal::of(array_sum($values))->dividedBy(count($values), 0, RoundingMode::HALF_UP)->toInt();
    }

    /**
     * @return array{invited: int, answered: int, response_rate: ?int, average: ?int, average_label: string, satisfaction_rate: ?int, questions: list<array<string, mixed>>}
     */
    public static function campaign(SurveyCampaign $campaign): array
    {
        $invited = $campaign->invitations()->count();
        $responses = SurveyResponse::query()
            ->whereIn('invitation_id', $campaign->invitations()->select('id'))
            ->orderByDesc('submitted_at')->get(['answers', 'score']);
        $scores = $responses->pluck('score')->filter(fn ($s) => $s !== null)->map(fn ($s) => (int) $s)->values()->all();
        $average = self::mean($scores);

        $questions = [];
        foreach ($campaign->questions as $q) {
            $values = $responses->map(fn (SurveyResponse $r) => $r->answers[$q['id']] ?? null)->filter(fn ($v) => $v !== null && $v !== '')->values();
            $row = ['id' => $q['id'], 'type' => $q['type'], 'label' => $q['label'], 'answers' => $values->count()];
            if ($q['type'] === 'rating') {
                $marks = array_values($values->filter(fn ($v) => is_int($v))->all());
                $row['average'] = self::mean(array_map(fn (int $m) => $m * 100, $marks));
                $row['average_label'] = SurveyScoring::label($row['average']);
                $row['distribution'] = array_map(fn (int $m) => count(array_filter($marks, fn ($v) => $v === $m)), [1, 2, 3, 4, 5]);
            } elseif ($q['type'] === 'choice') {
                $counts = [];
                foreach ((array) ($q['options'] ?? []) as $option) {
                    $counts[(string) $option] = $values->filter(fn ($v) => $v === $option)->count();
                }
                $row['counts'] = $counts;
            } else {
                $row['texts'] = $values->map(fn ($v) => (string) $v)->all();
            }
            $questions[] = $row;
        }

        return [
            'invited' => $invited,
            'answered' => $responses->count(),
            'response_rate' => self::rate($responses->count(), $invited),
            'average' => $average,
            'average_label' => SurveyScoring::label($average),
            'satisfaction_rate' => self::rate(count(array_filter($scores, fn (int $s) => $s >= SurveyScoring::SATISFIED)), count($scores)),
            'questions' => $questions,
        ];
    }
}
