<?php

namespace App\Modules\Core\CustomFields;

use Closure;

/**
 * A kind of fiche that carries custom fields, declared by its module
 * (CustomFieldEntities::register). The module owns the values (a
 * custom_values jsonb keyed by the field id) and tells Core whether a field
 * or one of its choices is used, so Core never reads the module's tables.
 */
final readonly class CustomFieldEntity
{
    /**
     * @param  string  $key  stored in custom_field_definitions.entity
     * @param  string  $label  tab of Administration › Champs personnalisés (« Formations »)
     * @param  string  $records  the fiches in a sentence (« des formations »)
     * @param  string  $description  where the fields appear
     * @param  string  $requiredLabel  checkbox « obligatoire » of the settings screen
     * @param  list<CustomFieldType>  $types  types offered
     * @param  Closure(CustomFieldDefinition): bool  $used  a fiche (trashed ones included) holds a value
     * @param  Closure(CustomFieldDefinition, string): bool  $choiceUsed  a fiche holds this choice
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $records,
        public string $description,
        public string $requiredLabel,
        public array $types,
        public Closure $used,
        public Closure $choiceUsed,
        public int $position = 100,
    ) {}

    public function offers(CustomFieldType $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function typeOptions(): array
    {
        return array_map(fn (CustomFieldType $t) => ['value' => $t->value, 'label' => $t->label()], $this->types);
    }
}
