<?php

namespace App\Modules\Questionnaires\Surveys;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The questions of a template or a campaign: 1 to MAX_QUESTIONS questions
 * {id, type rating|choice|text, label, required, options (choice only)}; a
 * rating is a mark from 1 (not satisfied at all) to 5 (very satisfied), a
 * choice a single option among 2 to 10. Also checks the answers of a
 * respondent against them.
 */
final class SurveyContent
{
    public const TYPES = ['rating', 'choice', 'text'];

    public const MAX_QUESTIONS = 30;

    public const MAX_LABEL = 300;

    public const MAX_OPTION = 120;

    public const MAX_TEXT = 2000;

    /**
     * @return list<array{id: string, type: string, label: string, required: bool, options?: list<string>}>
     *
     * @throws ValidationException
     */
    public static function validate(mixed $questions): array
    {
        if (! is_array($questions) || ! array_is_list($questions) || $questions === []) {
            throw ValidationException::withMessages(['questions' => 'Ajoutez au moins une question.']);
        }
        if (count($questions) > self::MAX_QUESTIONS) {
            throw ValidationException::withMessages(['questions' => 'Trente questions au plus.']);
        }
        $errors = [];
        $out = [];
        $ids = [];
        foreach ($questions as $i => $q) {
            $q = is_array($q) ? $q : [];
            $id = is_string($q['id'] ?? null) && $q['id'] !== '' ? $q['id'] : strtolower(Str::random(8));
            if (preg_match('/^[a-z0-9_-]{1,20}$/i', $id) !== 1 || in_array($id, $ids, true)) {
                $errors["questions.{$i}.id"] = 'Identifiant de question invalide ou en double.';
            }
            $ids[] = $id;
            $type = $q['type'] ?? null;
            if (! in_array($type, self::TYPES, true)) {
                $errors["questions.{$i}.type"] = 'Type de question inconnu.';
            }
            $label = is_string($q['label'] ?? null) ? trim($q['label']) : '';
            if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
                $errors["questions.{$i}.label"] = 'Saisissez la question (300 caractères au plus).';
            }
            $question = ['id' => $id, 'type' => (string) $type, 'label' => $label, 'required' => (bool) ($q['required'] ?? false)];
            if ($type === 'choice') {
                $options = array_values(array_map(fn ($o) => is_string($o) ? trim($o) : '', is_array($q['options'] ?? null) ? $q['options'] : []));
                if (count($options) < 2 || count($options) > 10 || in_array('', $options, true)
                    || count(array_unique($options)) !== count($options) || max(array_map('mb_strlen', $options)) > self::MAX_OPTION) {
                    $errors["questions.{$i}.options"] = 'De 2 à 10 choix, différents, de 120 caractères au plus.';
                }
                $question['options'] = $options;
            }
            $out[] = $question;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * The answers kept (question id => 1-5, option or trimmed text); empty
     * answers to optional questions and unknown keys are left out.
     *
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, int|string>
     *
     * @throws ValidationException
     */
    public static function validateAnswers(array $questions, mixed $answers): array
    {
        $answers = is_array($answers) ? $answers : [];
        $errors = [];
        $out = [];
        foreach ($questions as $q) {
            $id = (string) $q['id'];
            $raw = $answers[$id] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            if ($value === null || $value === '') {
                if ($q['required'] ?? false) {
                    $errors["answers.{$id}"] = 'Cette question est obligatoire.';
                }

                continue;
            }
            switch ($q['type']) {
                case 'rating':
                    $mark = is_int($value) ? $value : (is_string($value) && preg_match('/^[1-5]$/', $value) === 1 ? (int) $value : null);
                    if ($mark === null || $mark < 1 || $mark > 5) {
                        $errors["answers.{$id}"] = 'Choisissez une note de 1 à 5.';
                    } else {
                        $out[$id] = $mark;
                    }
                    break;
                case 'choice':
                    if (! is_string($value) || ! in_array($value, $q['options'] ?? [], true)) {
                        $errors["answers.{$id}"] = 'Choisissez une des réponses proposées.';
                    } else {
                        $out[$id] = $value;
                    }
                    break;
                default:
                    if (! is_string($value) || mb_strlen($value) > self::MAX_TEXT) {
                        $errors["answers.{$id}"] = 'Réponse trop longue (2 000 caractères au plus).';
                    } else {
                        $out[$id] = $value;
                    }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
