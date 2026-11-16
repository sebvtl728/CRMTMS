<?php

namespace App\Modules\Catalogue\Brochures;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of a brochure (deposit and update). Authorisation is done by the
 * route middleware (catalogue.manage) and the policy. The file is required to
 * deposit, optional to update (a file adds a version).
 */
class BrochureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $title = $this->input('title');
            $this->merge(['title' => is_string($title) ? trim($title) : $title]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'title' => ['required', 'string', 'max:200'],
            ...BrochureFile::rules(required: ! $this->route('brochure') instanceof Brochure),
            'training_ids' => ['sometimes', 'nullable', 'array'],
            'training_ids.*' => ['bail', 'distinct', 'uuid', Rule::exists('trainings', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'das_ids' => ['sometimes', 'nullable', 'array'],
            'das_ids.*' => ['bail', 'distinct', 'uuid', Rule::exists('reference_items', 'id')
                ->where('tenant_id', $tenantId)->where('list', ReferenceList::Das->value)->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...BrochureFile::messages(),
            'required' => 'Ce champ est obligatoire.',
            'max' => 'Ce champ est trop long.',
            'string' => 'Cette valeur n\'est pas valide.',
            'array' => 'Cette valeur n\'est pas valide.',
            'distinct' => 'Cette valeur est en double.',
            'uuid' => 'Cette valeur n\'est pas valide.',
            'exists' => 'Cette valeur n\'est pas valide.',
        ];
    }

    /**
     * Linked ids as validated: null when the request did not send the key
     * (unchanged on update), a list otherwise.
     *
     * @return list<string>|null
     */
    public function ids(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        return array_values(array_map('strval', (array) ($this->validated()[$key] ?? [])));
    }
}
