<?php

namespace App\Modules\Core\CustomFields;

use Illuminate\Validation\ValidationException;

/**
 * The values typed in the custom fields of a fiche: checked against the
 * visible fields of its entity and normalised (trimmed text, a choice among
 * the options). Unknown and hidden fields are dropped; an empty value
 * removes the key. A required field must be filled once the values are
 * merged (hidden ones apart). Covers the types without a module rule (text,
 * long text, choice); Admissions keeps its own (number, date, file, NIR…).
 */
final class CustomFieldInput
{
    public const TEXT_MAX = 255;

    public const TEXTAREA_MAX = 5000;

    /**
     * $current with the typed $values applied.
     *
     * @param  array<string, mixed>  $current  stored values (kept for hidden or unknown fields)
     * @param  array<mixed>  $values  typed values, keyed by field id
     * @return array<string, string>
     *
     * @throws ValidationException errors keyed custom_values.<id>
     */
    public static function apply(string $entity, array $current, array $values): array
    {
        $fields = app(CustomFieldDefinitions::class)->active($entity);
        $errors = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field->id, $values)) {
                continue;
            }
            $value = $values[$field->id];
            $value = is_string($value) ? trim($value) : $value;
            if ($value === null || $value === '') {
                unset($current[$field->id]);

                continue;
            }
            $error = self::error($field, $value);
            if ($error !== null) {
                $errors["custom_values.{$field->id}"] = [$error];
            } else {
                $current[$field->id] = (string) $value;
            }
        }
        foreach ($fields as $field) {
            if ($field->required_for_admission && ! isset($errors["custom_values.{$field->id}"]) && ! array_key_exists($field->id, $current)) {
                $errors["custom_values.{$field->id}"] = ["Le champ « {$field->label} » est obligatoire."];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var array<string, string> $current */
        return $current;
    }

    /**
     * The visible fields of $entity with the value of a fiche (null when
     * empty), for its page.
     *
     * @param  array<string, mixed>  $values
     * @return list<array{label: string, value: string|null}>
     */
    public static function display(string $entity, array $values): array
    {
        return app(CustomFieldDefinitions::class)->active($entity)
            ->map(fn (CustomFieldDefinition $f) => ['label' => $f->label, 'value' => isset($values[$f->id]) ? (string) $values[$f->id] : null])
            ->values()->all();
    }

    /**
     * The visible fields of $entity for a form.
     *
     * @return list<array{id: string, label: string, type: string, options: list<string>, required: bool}>
     */
    public static function form(string $entity): array
    {
        return app(CustomFieldDefinitions::class)->active($entity)
            ->map(fn (CustomFieldDefinition $f) => [
                'id' => $f->id,
                'label' => $f->label,
                'type' => $f->type->value,
                'options' => $f->options ?? [],
                'required' => $f->required_for_admission,
            ])->values()->all();
    }

    private static function error(CustomFieldDefinition $field, mixed $value): ?string
    {
        return match ($field->type) {
            CustomFieldType::Text, CustomFieldType::Textarea => ! is_string($value) || mb_strlen($value) > ($field->type === CustomFieldType::Text ? self::TEXT_MAX : self::TEXTAREA_MAX)
                ? "Le champ « {$field->label} » est trop long."
                : null,
            CustomFieldType::Choice => ! is_string($value) || ! in_array($value, $field->options ?? [], true)
                ? "Choisissez une valeur de la liste « {$field->label} »."
                : null,
            default => "Le champ « {$field->label} » n'est pas modifiable ici.",
        };
    }
}
