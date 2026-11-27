<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\CustomFields\CustomFieldDefinition;
use App\Modules\Core\CustomFields\CustomFieldDefinitions;
use App\Modules\Core\CustomFields\CustomFieldEntities;
use App\Modules\Core\CustomFields\CustomFieldEntity;
use App\Modules\Core\CustomFields\CustomFieldInput;
use App\Modules\Core\CustomFields\CustomFieldType;

/**
 * The organisme's own fields of a training (Administration › Champs
 * personnalisés, tab « Formations »): typed in the Présentation card of the
 * form, shown on the page, exported (one column « custom:<id> » per visible
 * field) and printed in the programme when filled. Values live in
 * trainings.custom_values, keyed by the id of the field.
 */
final class TrainingCustomFields
{
    public const ENTITY = 'training';

    /** Prefix of the export columns. */
    public const COLUMN_PREFIX = 'custom:';

    /** Declares the training fields to Core (CatalogueServiceProvider::register). */
    public static function registerEntity(): void
    {
        CustomFieldEntities::register(new CustomFieldEntity(
            key: self::ENTITY,
            label: 'Formations',
            records: 'des formations',
            description: 'Vos propres champs sur la fiche formation du catalogue, dans la carte « Présentation » ; repris dans l\'export et le programme (PDF).',
            requiredLabel: 'Obligatoire pour enregistrer une formation',
            types: [CustomFieldType::Text, CustomFieldType::Textarea, CustomFieldType::Choice],
            used: fn (CustomFieldDefinition $field): bool => Training::withTrashed()->whereRaw('jsonb_exists(custom_values, ?)', [$field->id])->exists(),
            choiceUsed: fn (CustomFieldDefinition $field, string $choice): bool => Training::withTrashed()->whereRaw('custom_values ->> ? = ?', [$field->id, $choice])->exists(),
            position: 20,
        ));
    }

    /**
     * The stored values with the typed ones applied.
     *
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    public static function apply(?Training $training, array $values): array
    {
        return CustomFieldInput::apply(self::ENTITY, $training === null ? [] : $training->custom_values, $values);
    }

    /**
     * @return list<array{id: string, label: string, type: string, options: list<string>, required: bool}>
     */
    public static function form(): array
    {
        return CustomFieldInput::form(self::ENTITY);
    }

    /**
     * @return list<array{label: string, value: string|null}>
     */
    public static function display(Training $training): array
    {
        return CustomFieldInput::display(self::ENTITY, $training->custom_values ?? []);
    }

    /**
     * The filled visible fields, for the programme.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function filled(Training $training): array
    {
        return array_values(array_filter(self::display($training), fn (array $f) => $f['value'] !== null));
    }

    /**
     * Export columns of the visible fields: « custom:<id> » => label.
     *
     * @return array<string, string>
     */
    public static function columns(): array
    {
        $columns = [];
        foreach (app(CustomFieldDefinitions::class)->active(self::ENTITY) as $field) {
            $columns[self::COLUMN_PREFIX.$field->id] = $field->label;
        }

        return $columns;
    }

    /** The value of an export column « custom:<id> » (null for any other). */
    public static function value(Training $training, string $column): ?string
    {
        if (! str_starts_with($column, self::COLUMN_PREFIX)) {
            return null;
        }
        $value = ($training->custom_values ?? [])[substr($column, strlen(self::COLUMN_PREFIX))] ?? null;

        return $value === null ? null : (string) $value;
    }
}
