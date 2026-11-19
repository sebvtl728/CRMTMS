<?php

namespace App\Modules\Admissions\CustomFields;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureFile;
use Illuminate\Validation\ValidationException;

/**
 * The values of the custom fields of a record: checked against the visible
 * fields of the organisme and normalised (number as a decimal string, date
 * Y-m-d, boolean, a choice among the options). Unknown, hidden and « file »
 * fields are dropped (a file goes through CandidatureFiles); an empty value
 * removes the key.
 */
final class CustomFieldValues
{
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> normalised; null for a key to remove
     *
     * @throws ValidationException
     */
    public static function validate(string $entity, array $values): array
    {
        $fields = app(CustomFieldDefinitions::class)->active($entity)->keyBy('id');
        $clean = [];
        $errors = [];

        foreach ($values as $id => $value) {
            $field = $fields->get((string) $id);
            if ($field === null || $field->type === CustomFieldType::File) {
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            if ($value === null || $value === '') {
                $clean[$field->id] = null;

                continue;
            }
            $key = "custom_values.{$field->id}";
            $label = $field->label;
            switch ($field->type) {
                case CustomFieldType::Text:
                case CustomFieldType::Textarea:
                    $max = $field->type === CustomFieldType::Text ? 255 : 5000;
                    if (! is_string($value) || mb_strlen($value) > $max) {
                        $errors[$key] = ["Le champ « {$label} » est trop long."];
                    } else {
                        $clean[$field->id] = $value;
                    }
                    break;
                case CustomFieldType::Number:
                    $number = is_string($value) || is_int($value) || is_float($value) ? str_replace([' ', ','], ['', '.'], (string) $value) : '';
                    if (preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $number) !== 1) {
                        $errors[$key] = ["Le champ « {$label} » attend un nombre."];
                    } else {
                        $clean[$field->id] = $number;
                    }
                    break;
                case CustomFieldType::Date:
                    $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                    if ($date === false || $date->format('Y-m-d') !== $value) {
                        $errors[$key] = ["Le champ « {$label} » attend une date."];
                    } else {
                        $clean[$field->id] = $value;
                    }
                    break;
                case CustomFieldType::Boolean:
                    $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($bool === null) {
                        $errors[$key] = ["Le champ « {$label} » attend oui ou non."];
                    } else {
                        $clean[$field->id] = $bool;
                    }
                    break;
                case CustomFieldType::Choice:
                    if (! is_string($value) || ! in_array($value, $field->options ?? [], true)) {
                        $errors[$key] = ["Choisissez une valeur de la liste « {$label} »."];
                    } else {
                        $clean[$field->id] = $value;
                    }
                    break;
                case CustomFieldType::File:
                    break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * $current with $changes applied (null removes a key).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function merge(array $current, array $changes): array
    {
        foreach ($changes as $id => $value) {
            if ($value === null) {
                unset($current[$id]);
            } else {
                $current[$id] = $value;
            }
        }

        return $current;
    }

    /**
     * Labels of the visible fields required for the admission that
     * $candidature leaves empty (a « file » field: no file of its kind).
     *
     * @return list<string>
     */
    public static function missingForAdmission(Candidature $candidature): array
    {
        $kinds = CandidatureFile::query()->where('candidature_id', $candidature->id)->pluck('kind')->all();
        $missing = [];
        foreach (app(CustomFieldDefinitions::class)->active(CustomFieldDefinition::CANDIDATURE) as $field) {
            if (! $field->required_for_admission) {
                continue;
            }
            $filled = $field->type === CustomFieldType::File
                ? in_array('custom:'.$field->id, $kinds, true)
                : array_key_exists($field->id, $candidature->custom_values ?? []);
            if (! $filled) {
                $missing[] = $field->label;
            }
        }

        return $missing;
    }

    /**
     * The visible fields with the value of $candidature, for its page.
     *
     * @return list<array{id: string, label: string, type: string, options: list<string>|null, required_for_admission: bool, value: mixed}>
     */
    public static function forPage(Candidature $candidature): array
    {
        return app(CustomFieldDefinitions::class)->active(CustomFieldDefinition::CANDIDATURE)
            ->map(fn (CustomFieldDefinition $f) => [
                'id' => $f->id,
                'label' => $f->label,
                'type' => $f->type->value,
                'options' => $f->options,
                'required_for_admission' => $f->required_for_admission,
                'value' => ($candidature->custom_values ?? [])[$f->id] ?? null,
            ])->values()->all();
    }
}
