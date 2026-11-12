<?php

namespace App\Modules\Crm\Duplicates;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /crm/merge/{type}: the two fiches and the value retained for each
 * field ('keep' | 'drop'). Authorisation: route middleware crm.merge.
 */
class MergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function fieldsOfType(): array
    {
        return $this->route('type') === 'person' ? Merger::PERSON_FIELDS : Merger::ORGANISATION_FIELDS;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'keep' => ['required', 'string', 'uuid'],
            'drop' => ['required', 'string', 'uuid'],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['string', 'in:keep,drop'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'uuid' => 'Fiche introuvable.',
            'in' => 'Choisissez l\'une des deux valeurs.',
            'array' => 'Choix invalides.',
            'string' => 'Choix invalide.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = $this->input('fields', []);
            if (is_array($fields) && array_diff(array_keys($fields), array_keys($this->fieldsOfType())) !== []) {
                $validator->errors()->add('fields', 'Ces champs ne peuvent pas être fusionnés.');
            }
            if (is_string($this->input('keep')) && $this->input('keep') === $this->input('drop')) {
                $validator->errors()->add('drop', 'Une fiche ne peut pas être fusionnée avec elle-même.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function choices(): array
    {
        /** @var array<string, string> $fields */
        $fields = $this->validated('fields', []);

        return $fields;
    }
}
