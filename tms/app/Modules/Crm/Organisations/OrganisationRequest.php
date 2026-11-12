<?php

namespace App\Modules\Crm\Organisations;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Visibility\CrmVisibility;
use App\Rules\Siret;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation of an entreprise (create and update). Authorisation is done by
 * the route middleware (crm.create / crm.update).
 */
class OrganisationRequest extends FormRequest
{
    public const DUPLICATE_SIRET = 'Une entreprise avec ce SIRET existe déjà.';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['name', 'siret', 'naf_code', 'email', 'phone', 'website', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'notes_summary'] as $key) {
            if ($this->has($key)) {
                $value = is_string($this->input($key)) ? trim($this->input($key)) : $this->input($key);
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        if (is_string($clean['siret'] ?? null)) {
            $clean['siret'] = preg_replace('/[\s.]/u', '', $clean['siret']);
        }
        if (is_string($clean['naf_code'] ?? null)) {
            $clean['naf_code'] = mb_strtoupper($clean['naf_code']);
        }
        if (is_string($clean['country'] ?? null)) {
            $clean['country'] = mb_strtoupper($clean['country']);
        }
        if (is_string($clean['website'] ?? null) && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $clean['website'])) {
            $clean['website'] = 'https://'.$clean['website'];
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'siret' => ['nullable', 'string', new Siret],
            'naf_code' => ['nullable', 'string', 'regex:/^\d{2}\.?\d{2}[A-Z]$/'],
            'legal_form_id' => ['nullable', $this->reference(ReferenceList::LegalFormOrg)],
            'sector_id' => ['nullable', $this->reference(ReferenceList::Sector)],
            'establishment_type_id' => ['nullable', $this->reference(ReferenceList::EstablishmentType)],
            'company_size_id' => ['nullable', $this->reference(ReferenceList::CompanySize)],
            'origin_id' => ['nullable', $this->reference(ReferenceList::ContactOrigin)],
            'status' => ['sometimes', Rule::enum(OrganisationStatus::class)],
            'responsible_user_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', app(TenantContext::class)->id())->whereNull('deactivated_at')],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'notes_summary' => ['nullable', 'string', 'max:5000'],
            'das_ids' => ['sometimes', 'array'],
            'das_ids.*' => ['distinct', $this->reference(ReferenceList::Das)],
        ];
    }

    private function reference(ReferenceList $list): Exists
    {
        return Rule::exists('reference_items', 'id')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('list', $list->value)
            ->whereNull('deleted_at');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'max' => 'Ce champ est trop long.',
            'email' => 'L\'adresse e-mail n\'est pas valide.',
            'url' => 'L\'adresse du site n\'est pas valide.',
            'exists' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'naf_code.regex' => 'Le code NAF doit être de la forme 5610A.',
            'country.size' => 'Indiquez le code pays sur 2 lettres (FR).',
            'country.alpha' => 'Indiquez le code pays sur 2 lettres (FR).',
            'enum' => 'Cette valeur n\'est pas valide.',
            'distinct' => 'Cette valeur est en double.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $siret = $this->input('siret');

            if (! is_string($siret) || $validator->errors()->has('siret')) {
                return;
            }

            $current = $this->route('organisation');
            $existing = Organisation::query()
                ->where('siret', $siret)
                ->when($current instanceof Organisation, fn ($q) => $q->whereKeyNot($current->getKey()))
                ->first();

            if ($existing === null) {
                return;
            }

            self::addDuplicateSiretError($validator, $existing, $this->user());
        }];
    }

    public static function addDuplicateSiretError(Validator $validator, Organisation $existing, mixed $user): void
    {
        $validator->errors()->add('siret', self::DUPLICATE_SIRET);

        // The link is only offered to someone who may open that fiche.
        if ($user instanceof User && CrmVisibility::organisations(Organisation::query(), $user)->whereKey($existing->getKey())->exists()) {
            $validator->errors()->add('siret_existing_id', $existing->getKey());
        }
    }
}
