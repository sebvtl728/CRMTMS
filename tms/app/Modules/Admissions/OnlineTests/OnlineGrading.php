<?php

namespace App\Modules\Admissions\OnlineTests;

use Brick\Math\BigDecimal;

/**
 * Grading of an attempt, pure (no database, no clock): a closed question
 * with a right answer earns its points or 0 (multiple choice all or
 * nothing, number within its tolerance); a question without right answer
 * and with points is hand-graded; one without points is profile only.
 */
final class OnlineGrading
{
    /**
     * Whether a question is graded automatically.
     *
     * @param  array<string, mixed>  $question
     */
    public static function automatic(array $question): bool
    {
        return in_array($question['type'], ['single_choice', 'multiple_choice', 'true_false', 'number'], true) && ($question['answer'] ?? null) !== null;
    }

    /**
     * Whether a question is graded by hand.
     *
     * @param  array<string, mixed>  $question
     */
    public static function manual(array $question): bool
    {
        return ! self::automatic($question) && (int) $question['points'] > 0;
    }

    /**
     * Points earned on a closed question (null: not automatic).
     *
     * @param  array<string, mixed>  $question
     */
    public static function points(array $question, mixed $given): ?int
    {
        if (! self::automatic($question)) {
            return null;
        }
        $right = match ($question['type']) {
            'single_choice' => is_string($given) && $given === $question['answer'],
            'multiple_choice' => is_array($given) && self::sameSet($given, (array) $question['answer']),
            'true_false' => is_bool($given) && $given === $question['answer'],
            'number' => self::closeEnough($given, (string) $question['answer'], (string) ($question['tolerance'] ?? OnlineTestContent::TOLERANCE)),
            default => false,
        };

        return $right ? (int) $question['points'] : 0;
    }

    /**
     * Automatic score, hand-graded questions left, final score when none is left.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $answers
     * @param  array<string, int>  $manualScores
     * @return array{auto: int, pending: list<string>, score: int|null}
     */
    public static function grade(array $content, array $answers, array $manualScores): array
    {
        $auto = 0;
        $manual = 0;
        $pending = [];
        foreach ($content['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $points = self::points($question, $answers[$question['id']] ?? null);
                if ($points !== null) {
                    $auto += $points;
                } elseif (self::manual($question)) {
                    if (array_key_exists($question['id'], $manualScores)) {
                        $manual += (int) $manualScores[$question['id']];
                    } else {
                        $pending[] = (string) $question['id'];
                    }
                }
            }
        }

        return ['auto' => $auto, 'pending' => $pending, 'score' => $pending === [] ? $auto + $manual : null];
    }

    /**
     * @param  array<int, mixed>  $a
     * @param  array<int, mixed>  $b
     */
    private static function sameSet(array $a, array $b): bool
    {
        $a = array_values(array_unique(array_map('strval', $a)));
        $b = array_values(array_unique(array_map('strval', $b)));
        sort($a);
        sort($b);

        return $a === $b;
    }

    private static function closeEnough(mixed $given, string $answer, string $tolerance): bool
    {
        $value = OnlineTestContent::decimal($given);

        return $value !== null && BigDecimal::of($value)->minus($answer)->abs()->isLessThanOrEqualTo(BigDecimal::of($tolerance));
    }
}
