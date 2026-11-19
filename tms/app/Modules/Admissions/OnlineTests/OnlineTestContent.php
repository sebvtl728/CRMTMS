<?php

namespace App\Modules\Admissions\OnlineTests;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The content of an online test: sections → questions. Types: short_text,
 * long_text (hand-graded), single_choice, multiple_choice, true_false,
 * number (auto-graded when an answer is set), yes_no (profile). Points are
 * integers from 0 to 100. validate() returns the content normalised
 * (trimmed texts, ids given to new questions and choices).
 */
final class OnlineTestContent
{
    public const TYPES = ['short_text', 'long_text', 'single_choice', 'multiple_choice', 'true_false', 'number', 'yes_no'];

    public const CHOICE_TYPES = ['single_choice', 'multiple_choice'];

    public const MAX_SECTIONS = 30;

    public const MAX_QUESTIONS = 200;

    /** Default tolerance of a number answer. */
    public const TOLERANCE = '0.01';

    /**
     * @param  array<string, mixed>  $content
     * @return array{sections: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public static function validate(array $content): array
    {
        $errors = [];
        $sections = is_array($content['sections'] ?? null) ? array_values($content['sections']) : [];
        if (count($sections) > self::MAX_SECTIONS) {
            $errors['sections'] = 'Trente sections au plus.';
        }
        $count = 0;
        $out = [];
        foreach ($sections as $s => $section) {
            $section = is_array($section) ? $section : [];
            $title = trim((string) ($section['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 200) {
                $errors["sections.{$s}.title"] = 'Donnez un titre à la section (200 caractères au plus).';
            }
            $questions = [];
            foreach (is_array($section['questions'] ?? null) ? array_values($section['questions']) : [] as $q => $question) {
                $count++;
                $questions[] = self::question(is_array($question) ? $question : [], "sections.{$s}.questions.{$q}", $errors);
            }
            $intro = trim((string) ($section['intro'] ?? ''));
            $out[] = ['title' => $title, 'intro' => $intro === '' ? null : mb_substr($intro, 0, 5000), 'questions' => $questions];
        }
        if ($count > self::MAX_QUESTIONS) {
            $errors['sections'] = 'Deux cents questions au plus.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['sections' => $out];
    }

    /** Total of the points. @param array{sections: list<array<string, mixed>>} $content */
    public static function total(array $content): int
    {
        $total = 0;
        foreach ($content['sections'] as $section) {
            foreach ($section['questions'] as $question) {
                $total += (int) $question['points'];
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $q
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private static function question(array $q, string $key, array &$errors): array
    {
        $type = (string) ($q['type'] ?? '');
        if (! in_array($type, self::TYPES, true)) {
            $errors["{$key}.type"] = 'Type de question inconnu.';
        }
        $label = trim((string) ($q['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 2000) {
            $errors["{$key}.label"] = 'Écrivez la question.';
        }
        $points = $q['points'] ?? 0;
        if (! is_int($points) && ! (is_string($points) && ctype_digit($points))) {
            $points = -1;
        }
        $points = (int) $points;
        if ($points < 0 || $points > 100) {
            $errors["{$key}.points"] = 'Les points vont de 0 à 100.';
        }
        $out = [
            'id' => is_string($q['id'] ?? null) && $q['id'] !== '' ? mb_substr($q['id'], 0, 64) : (string) Str::uuid7(),
            'type' => $type,
            'label' => $label,
            'support_text' => ($t = trim((string) ($q['support_text'] ?? ''))) === '' ? null : mb_substr($t, 0, 20000),
            'image' => is_string($q['image'] ?? null) && $q['image'] !== '' ? $q['image'] : null,
            'points' => max(0, $points),
            'required' => (bool) ($q['required'] ?? false),
            'answer' => null,
        ];

        if (in_array($type, self::CHOICE_TYPES, true)) {
            $choices = [];
            foreach (is_array($q['choices'] ?? null) ? $q['choices'] : [] as $choice) {
                $text = trim((string) (is_array($choice) ? ($choice['label'] ?? '') : $choice));
                if ($text !== '') {
                    $choices[] = ['id' => is_array($choice) && is_string($choice['id'] ?? null) && $choice['id'] !== '' ? mb_substr($choice['id'], 0, 64) : (string) Str::uuid7(), 'label' => mb_substr($text, 0, 500)];
                }
            }
            if (count($choices) < 2) {
                $errors["{$key}.choices"] = 'Proposez au moins deux choix.';
            }
            $out['choices'] = $choices;
            $ids = array_column($choices, 'id');
            $answer = $q['answer'] ?? null;
            if ($type === 'single_choice' && $answer !== null && $answer !== '') {
                if (! is_string($answer) || ! in_array($answer, $ids, true)) {
                    $errors["{$key}.answer"] = 'La bonne réponse doit être un des choix.';
                } else {
                    $out['answer'] = $answer;
                }
            }
            if ($type === 'multiple_choice' && is_array($answer) && $answer !== []) {
                if (array_diff($answer, $ids) !== []) {
                    $errors["{$key}.answer"] = 'La bonne réponse doit être un des choix.';
                } else {
                    $out['answer'] = array_values(array_unique(array_map('strval', $answer)));
                }
            }
        } elseif ($type === 'true_false' && is_bool($q['answer'] ?? null)) {
            $out['answer'] = $q['answer'];
        } elseif ($type === 'number' && ($q['answer'] ?? null) !== null && $q['answer'] !== '') {
            $answer = self::decimal($q['answer']);
            $tolerance = self::decimal($q['tolerance'] ?? self::TOLERANCE);
            if ($answer === null) {
                $errors["{$key}.answer"] = 'La bonne réponse doit être un nombre.';
            } else {
                $out['answer'] = $answer;
                $out['tolerance'] = $tolerance ?? self::TOLERANCE;
            }
        }

        return $out;
    }

    /** A decimal string (comma or dot), or null when not a number. */
    public static function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $value));
        if (preg_match('/^-?\d{1,12}(\.\d{1,6})?$/', $text) !== 1) {
            return null;
        }
        try {
            return (string) BigDecimal::of($text)->stripTrailingZeros();
        } catch (MathException) {
            return null;
        }
    }
}
