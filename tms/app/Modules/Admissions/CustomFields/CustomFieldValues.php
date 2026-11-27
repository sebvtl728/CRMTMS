<?php

namespace App\Modules\Admissions\CustomFields;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureFile;
use App\Modules\Core\CustomFields\CustomFieldDefinition;
use App\Modules\Core\CustomFields\CustomFieldDefinitions;
use App\Modules\Core\CustomFields\CustomFieldEntities;
use App\Modules\Core\CustomFields\CustomFieldEntity;
use App\Modules\Core\CustomFields\CustomFieldType;
use Illuminate\Validation\ValidationException;

/**
 * The values of the custom fields of a record: checked against the visible
 * fields of the organisme and normalised (number as a decimal string, date
 * Y-m-d, boolean, a choice among the options). Unknown, hidden and « file »
 * fields are dropped (a file goes through CandidatureFiles); an empty value
 * removes the key. A sensitive field (social security number) is dropped
 * for a user without admissions.sensitive.view, and absent from his page.
 */
final class CustomFieldValues
{
    /** custom_field_definitions.entity of the candidature fields. */
    public const ENTITY = 'candidature';

    /** Declares the candidature fields to Core (AdmissionsServiceProvider::register). */
    public static function registerEntity(): void
    {
        CustomFieldEntities::register(new CustomFieldEntity(
            key: self::ENTITY,
            label: 'Candidatures',
            records: 'des candidatures',
            description: 'Vos propres champs sur la fiche candidat, dans le bloc « Informations complémentaires ».',
            requiredLabel: 'Obligatoire pour admettre un candidat',
            types: CustomFieldType::cases(),
            used: fn (CustomFieldDefinition $field): bool => Candidature::withTrashed()->whereRaw('jsonb_exists(custom_values, ?)', [$field->id])->exists()
                || CandidatureFile::query()->where('kind', 'custom:'.$field->id)->exists(),
            choiceUsed: fn (CustomFieldDefinition $field, string $choice): bool => Candidature::withTrashed()->whereRaw('custom_values ->> ? = ?', [$field->id, $choice])->exists(),
            position: 10,
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> normalised; null for a key to remove
     *
     * @throws ValidationException
     */
    public static function validate(string $entity, array $values, bool $sensitive = true): array
    {
        $fields = app(CustomFieldDefinitions::class)->active($entity)->keyBy('id');
        $clean = [];
        $errors = [];

        foreach ($values as $id => $value) {
            $field = $fields->get((string) $id);
            if ($field === null || $field->type === CustomFieldType::File || (! $sensitive && $field->type->sensitive())) {
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
                        // A long run of digits is an identifier (social security, phone), not a quantity.
                        $errors[$key] = [preg_match('/^\d{13,}$/', preg_replace('/[\s.]/', '', $number) ?? '') === 1
                            ? "Le champ « {$label} » attend un nombre de 12 chiffres au plus. Pour un numéro (sécurité sociale, téléphone…), donnez-lui le type Texte dans Administration › Champs personnalisés."
                            : "Le champ « {$label} » attend un nombre."];
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
                case CustomFieldType::Nir:
                    $nir = is_string($value) ? self::nir($value) : null;
                    if ($nir === null) {
                        $errors[$key] = ["Le numéro de sécurité sociale « {$label} » n'est pas valide : vérifiez les 15 chiffres, clé comprise."];
                    } else {
                        $clean[$field->id] = $nir;
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
     * The social security number (NIR) without spaces, dots nor dashes, in
     * capitals, when it is valid: sex 1, 2, 3, 4, 7 or 8, year, month,
     * department (2A / 2B for Corsica, counted 19 / 18), town, order, then
     * the key 97 − (13 first digits mod 97). Null otherwise.
     */
    public static function nir(string $value): ?string
    {
        $nir = mb_strtoupper(preg_replace('/[\s.\-]/u', '', $value) ?? '');
        if (preg_match('/^[1-478]\d{4}(\d{2}|2A|2B)\d{8}$/', $nir) !== 1) {
            return null;
        }
        $body = substr($nir, 0, 5).strtr(substr($nir, 5, 2), ['2A' => '19', '2B' => '18']).substr($nir, 7, 6);

        return 97 - ((int) $body % 97) === (int) substr($nir, 13, 2) ? $nir : null;
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
        foreach (app(CustomFieldDefinitions::class)->active(self::ENTITY) as $field) {
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
     * A sensitive field is listed without its value (hidden) when $sensitive is false.
     *
     * @return list<array{id: string, label: string, type: string, options: list<string>|null, required_for_admission: bool, value: mixed, hidden: bool}>
     */
    public static function forPage(Candidature $candidature, bool $sensitive = true): array
    {
        return app(CustomFieldDefinitions::class)->active(self::ENTITY)
            ->map(function (CustomFieldDefinition $f) use ($candidature, $sensitive): array {
                $hidden = ! $sensitive && $f->type->sensitive();

                return [
                    'id' => $f->id,
                    'label' => $f->label,
                    'type' => $f->type->value,
                    'options' => $f->options,
                    'required_for_admission' => $f->required_for_admission,
                    'value' => $hidden ? null : (($candidature->custom_values ?? [])[$f->id] ?? null),
                    'hidden' => $hidden,
                ];
            })->values()->all();
    }
}
