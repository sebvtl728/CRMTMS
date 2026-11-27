<?php

namespace App\Modules\Core\CustomFields;

use App\Exceptions\UserFacingHttpException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The custom fields of the organisme, per entity (CustomFieldEntities). A
 * field with a value on a fiche of its entity (trashed ones included) is
 * « used »: it is hidden, never deleted; its type no longer changes and a
 * choice in use is never removed. The entity's module tells what « used » is.
 */
final class CustomFieldDefinitions
{
    public const MAX_OPTIONS = 50;

    /**
     * Visible fields of $entity, in order.
     *
     * @return Collection<int, CustomFieldDefinition>
     */
    public function active(string $entity): Collection
    {
        return CustomFieldDefinition::query()->where('entity', $entity)->whereNull('hidden_at')->orderBy('position')->orderBy('created_at')->get();
    }

    /**
     * Every field of $entity, hidden ones included, in order.
     *
     * @return Collection<int, CustomFieldDefinition>
     */
    public function all(string $entity): Collection
    {
        return CustomFieldDefinition::query()->where('entity', $entity)->orderBy('position')->orderBy('created_at')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $entity, array $data): CustomFieldDefinition
    {
        $values = $this->validated(self::entity($entity), $data, null);

        return DB::transaction(fn () => CustomFieldDefinition::query()->create([
            ...$values,
            'entity' => $entity,
            'position' => (int) CustomFieldDefinition::query()->where('entity', $entity)->max('position') + 1,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomFieldDefinition $field, array $data): CustomFieldDefinition
    {
        $values = $this->validated(self::entity($field->entity), $data, $field);
        $field->update($values);

        return $field->refresh();
    }

    /**
     * @param  list<string>  $ids  the new order of the fields of $entity
     */
    public function reorder(string $entity, array $ids): void
    {
        DB::transaction(function () use ($entity, $ids): void {
            foreach ($ids as $i => $id) {
                CustomFieldDefinition::query()->where('entity', $entity)->whereKey($id)->update(['position' => $i + 1]);
            }
        });
    }

    public function hide(CustomFieldDefinition $field): void
    {
        $field->update(['hidden_at' => now()]);
    }

    public function unhide(CustomFieldDefinition $field): void
    {
        $field->update(['hidden_at' => null]);
    }

    public function delete(CustomFieldDefinition $field): void
    {
        if ($this->used($field)) {
            throw new UserFacingHttpException(409, 'Ce champ est rempli sur '.self::entity($field->entity)->records.' : masquez-le plutôt que de le supprimer.');
        }
        $field->delete();
    }

    /** A fiche of its entity holds a value (or a file) of $field. */
    public function used(CustomFieldDefinition $field): bool
    {
        $entity = CustomFieldEntities::find($field->entity);

        return $entity !== null && ($entity->used)($field);
    }

    /**
     * Ids of the fields of this list that are used, in one pass per field.
     *
     * @param  iterable<CustomFieldDefinition>  $fields
     * @return array<string, true>
     */
    public function usedAmong(iterable $fields): array
    {
        $used = [];
        foreach ($fields as $field) {
            if ($this->used($field)) {
                $used[$field->id] = true;
            }
        }

        return $used;
    }

    /** The registered entity, a validation error otherwise. */
    private static function entity(string $key): CustomFieldEntity
    {
        return CustomFieldEntities::find($key) ?? throw ValidationException::withMessages(['entity' => 'Type de fiche inconnu.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(CustomFieldEntity $entity, array $data, ?CustomFieldDefinition $current): array
    {
        // « required » for every entity; « required_for_admission » (the column) still read.
        if (array_key_exists('required', $data)) {
            $data['required_for_admission'] = $data['required'];
        }
        unset($data['required']);
        if (is_string($data['label'] ?? null)) {
            $data['label'] = trim($data['label']);
        }
        if (is_array($data['options'] ?? null)) {
            $data['options'] = array_values(array_filter(array_map(fn ($o) => is_string($o) ? trim($o) : $o, $data['options']), fn ($o) => $o !== '' && $o !== null));
        }
        $creating = $current === null;
        $type = $data['type'] ?? $current?->type->value;

        $rules = [
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::enum(CustomFieldType::class)],
            'options' => [$type === CustomFieldType::Choice->value && ($creating || array_key_exists('options', $data) || array_key_exists('type', $data)) ? 'required' : 'nullable', 'array', 'max:'.self::MAX_OPTIONS],
            'options.*' => ['string', 'max:100', 'distinct'],
            'required_for_admission' => ['sometimes', 'boolean'],
        ];
        $validator = Validator::make($data, $rules, [
            'label.required' => 'Indiquez le libellé du champ.',
            'type.required' => 'Choisissez le type du champ.',
            'type.enum' => 'Type inconnu.',
            'options.required' => 'Indiquez au moins un choix.',
            'options.max' => 'Pas plus de '.self::MAX_OPTIONS.' choix.',
            'options.*.distinct' => 'Ce choix est en double.',
            'max' => 'Ce champ est trop long.',
        ]);
        $validator->after(function ($v) use ($entity, $data, $current): void {
            $asked = is_string($data['type'] ?? null) ? CustomFieldType::tryFrom($data['type']) : null;
            if ($asked !== null && ! $entity->offers($asked) && $asked !== $current?->type) {
                $v->errors()->add('type', 'Type non proposé pour ces fiches.');
            }
            if ($current === null || ! $this->used($current)) {
                return;
            }
            if (array_key_exists('type', $data) && $data['type'] !== $current->type->value) {
                $v->errors()->add('type', "Le type ne peut plus changer : {$entity->records} ont une valeur.");
            }
            if ($current->type === CustomFieldType::Choice && is_array($data['options'] ?? null)) {
                foreach (array_diff($current->options ?? [], $data['options']) as $removed) {
                    if (($entity->choiceUsed)($current, (string) $removed)) {
                        $v->errors()->add('options', "Ce choix est utilisé par {$entity->records} : masquez le champ plutôt que de le retirer.");
                        break;
                    }
                }
            }
        });
        $values = $validator->validate();

        if (($values['type'] ?? $current?->type->value) !== CustomFieldType::Choice->value) {
            $values['options'] = null;
        }
        if (array_key_exists('required_for_admission', $values)) {
            $values['required_for_admission'] = (bool) $values['required_for_admission'];
        }

        return $values;
    }
}
