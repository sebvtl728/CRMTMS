<?php

namespace App\Modules\Questionnaires\Surveys;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The score of a response: the mean of its ratings × 100 (433 = 4,33/5),
 * half up, null without any rating. A respondent is « satisfied » from
 * SATISFIED (a mean of 4/5).
 */
final class SurveyScoring
{
    public const SATISFIED = 400;

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     */
    public static function score(array $questions, array $answers): ?int
    {
        $marks = [];
        foreach ($questions as $q) {
            if ($q['type'] === 'rating' && is_int($answers[$q['id']] ?? null)) {
                $marks[] = $answers[$q['id']];
            }
        }
        if ($marks === []) {
            return null;
        }

        return BigDecimal::of(array_sum($marks) * 100)->dividedBy(count($marks), 0, RoundingMode::HALF_UP)->toInt();
    }

    /** « 4,3/5 » (one decimal, half up), « 5/5 », « - » without a score. */
    public static function label(?int $score): string
    {
        if ($score === null) {
            return '-';
        }
        $value = (string) BigDecimal::of($score)->dividedBy(100, 1, RoundingMode::HALF_UP);

        return str_replace('.', ',', preg_replace('/\.0$/', '', $value) ?? $value).'/5';
    }
}
