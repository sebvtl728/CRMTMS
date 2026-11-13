<?php

namespace App\Modules\Crm\Search;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create / update of a saved filter. The filters and columns are checked
 * against the keys the list of the resource really has: no arbitrary JSON.
 */
class SavedFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function resource(): CrmResource
    {
        $existing = $this->route('savedFilter');

        return $existing instanceof SavedFilter
            ? CrmResource::from($existing->resource)
            : (CrmResource::tryFrom((string) $this->input('resource')) ?? CrmResource::Organisations);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $updating = $this->route('savedFilter') instanceof SavedFilter;
        $resource = $this->resource();
        $presence = $updating ? 'sometimes' : 'required';

        $rules = [
            'resource' => $updating ? ['sometimes', Rule::in([$resource->value])] : ['required', Rule::in(array_column(CrmResource::cases(), 'value'))],
            'name' => [$presence, 'string', 'max:100'],
            'is_shared' => ['sometimes', 'boolean'],
            'filters' => [$updating ? 'sometimes' : 'present', 'array'],
            'columns' => ['sometimes', 'nullable', 'array', 'max:50'],
            'columns.*' => ['string', Rule::in(array_keys($resource->export()->columns()))],
        ];

        foreach ($resource->filterRules() as $key => $keyRules) {
            $rules["filters.$key"] = ['nullable', ...$keyRules];
        }

        return $rules;
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $filters = $this->input('filters');
            if (! is_array($filters)) {
                return;
            }
            $unknown = array_diff(array_map('strval', array_keys($filters)), $this->resource()->savableFilters());
            if ($unknown !== []) {
                $validator->errors()->add('filters', 'Ce filtre contient des critères inconnus.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Donnez un nom à ce filtre.',
            'name.max' => 'Le nom est limité à 100 caractères.',
            'resource.in' => 'Liste inconnue.',
            'columns.*.in' => 'Colonne inconnue.',
            'filters.*.in' => 'Valeur de filtre invalide.',
            'filters.*.uuid' => 'Valeur de filtre invalide.',
        ];
    }

    /**
     * Only the non-empty criteria are kept.
     *
     * @return array<string, mixed>
     */
    public function cleanFilters(): array
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->validated('filters', []);

        return array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== false);
    }
}
