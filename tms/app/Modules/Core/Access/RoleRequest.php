<?php

namespace App\Modules\Core\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Creation (POST: label, requires_2fa, copy_from) and change (PATCH: label
 * and/or requires_2fa) of a role. Length and uniqueness of the label are
 * checked again by RoleCatalogue, under lock.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route: can:roles.manage
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'requires_2fa' => [$creating ? 'required' : 'sometimes', 'boolean'],
            'copy_from' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Indiquez le libellé du rôle.',
            'label.string' => 'Le libellé doit contenir de 2 à 80 caractères.',
            'label.min' => 'Le libellé doit contenir de 2 à 80 caractères.',
            'label.max' => 'Le libellé doit contenir de 2 à 80 caractères.',
            'requires_2fa.required' => 'Indiquez si la double authentification est obligatoire.',
            'requires_2fa.boolean' => 'Indiquez si la double authentification est obligatoire.',
            'copy_from.uuid' => "Ce rôle n'existe pas.",
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('label'))) {
            $this->merge(['label' => trim(preg_replace('/\s+/u', ' ', $this->input('label')) ?? '')]);
        }
    }

    public function label(): ?string
    {
        return $this->has('label') ? (string) $this->validated('label') : null;
    }

    public function requires2fa(): ?bool
    {
        return $this->has('requires_2fa') ? filter_var($this->validated('requires_2fa'), FILTER_VALIDATE_BOOLEAN) : null;
    }

    /**
     * The role to copy, in the current organisme (RLS: another one's is unknown).
     */
    public function copyFrom(): ?RoleModel
    {
        $id = $this->validated('copy_from');
        if ($id === null || $id === '') {
            return null;
        }

        return RoleModel::query()->whereKey($id)->where('guard_name', RoleSeeder::GUARD)->first()
            ?? throw ValidationException::withMessages(['copy_from' => "Ce rôle n'existe pas."]);
    }
}
