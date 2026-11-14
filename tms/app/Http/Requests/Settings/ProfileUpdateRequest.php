<?php

namespace App\Http\Requests\Settings;

use App\Modules\Core\Users\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:254',
                // Unique per tenant (users of other tenants are invisible under RLS anyway).
                Rule::unique(User::class)->where('tenant_id', $this->user()->tenant_id)->ignore($this->user()->id),
            ],

            'daily_digest' => ['sometimes', 'boolean'],

            // The e-mail is the login identity: changing it needs the current password.
            'current_password' => [
                Rule::requiredIf(fn () => $this->string('email')->value() !== $this->user()->email),
                'nullable',
                'string',
                'current_password',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Saisissez votre mot de passe actuel pour changer d\'adresse e-mail.',
        ];
    }
}
