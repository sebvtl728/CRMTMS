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
        ];
    }
}
