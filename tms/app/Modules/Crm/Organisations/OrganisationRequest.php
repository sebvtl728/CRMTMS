<?php

namespace App\Modules\Crm\Organisations;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Support\ReferenceRules;
use App\Modules\Crm\Visibility\CrmVisibility;
use App\Rules\Siret;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of an entreprise (create and update). Authorisation is done by
 * the route middleware (crm.fiches.update).
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
        $this->merge(self::normalise($this->all()));
    }

    /**
     * Cleaning of the submitted text fields (screen and import): trimmed,
     * '' as null, SIRET without spaces or dots, NAF and country upper case,
     * website with a scheme. Only the keys present in $input are returned.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        $clean = [];

        foreach (['name', 'siret', 'naf_code', 'idcc', 'email', 'phone', 'website', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'notes_summary'] as $key) {
            if (array_key_exists($key, $input)) {
                $value = is_string($input[$key]) ? trim($input[$key]) : $input[$key];
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        if (is_string($clean['siret'] ?? null)) {
            $clean['siret'] = preg_replace('/[\s.]/u', '', $clean['siret']);
        }
        if (is_string($clean['naf_code'] ?? null)) {
            $clean['naf_code'] = mb_strtoupper($clean['naf_code']);
        }
        if (is_string($clean['idcc'] ?? null) && preg_match('/^\d{1,4}$/', $clean['idcc'])) {
            $clean['idcc'] = str_pad($clean['idcc'], 4, '0', STR_PAD_LEFT);
        }
        if (is_string($clean['country'] ?? null)) {
            $clean['country'] = mb_strtoupper($clean['country']);
        }
        if (is_string($clean['website'] ?? null) && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $clean['website'])) {
            $clean['website'] = 'https://'.$clean['website'];
        }

        return $clean;
    }

    /**
     * Rules of the fields typed as text (shared with the import, which
     * resolves the reference fields itself).
     *
     * @return array<string, list<mixed>>
     */
    public static function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'siret' => ['nullable', 'string', new Siret],
            'naf_code' => ['nullable', 'string', 'regex:/^\d{2}\.?\d{2}[A-Z]$/'],
            'idcc' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'status' => ['sometimes', Rule::enum(OrganisationStatus::class)],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'notes_summary' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->route('organisation');
        $current = $current instanceof Organisation ? $current : null;

        return [
            ...self::fieldRules(),
            'legal_form_id' => ['nullable', ...ReferenceRules::item(ReferenceList::LegalFormOrg, $current?->legal_form_id)],
            'sector_id' => ['nullable', ...ReferenceRules::item(ReferenceList::Sector, $current?->sector_id)],
            'establishment_type_id' => ['nullable', ...ReferenceRules::item(ReferenceList::EstablishmentType, $current?->establishment_type_id)],
            'company_size_id' => ['nullable', ...ReferenceRules::item(ReferenceList::CompanySize, $current?->company_size_id)],
            'origin_id' => ['nullable', ...ReferenceRules::item(ReferenceList::ContactOrigin, $current?->origin_id)],
            'responsible_user_id' => ['nullable', ...ReferenceRules::responsible($current?->responsible_user_id)],
            'type_ids' => ['sometimes', 'array'],
            'type_ids.*' => ['distinct', ...ReferenceRules::item(ReferenceList::OrganisationType, $current?->types()->pluck('reference_items.id')->all())],
            'das_ids' => ['sometimes', 'array'],
            'das_ids.*' => ['distinct', ...ReferenceRules::item(ReferenceList::Das, $current?->das()->pluck('reference_items.id')->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::validationMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function validationMessages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'max' => 'Ce champ est trop long.',
            'email' => 'L\'adresse e-mail n\'est pas valide.',
            'url' => 'L\'adresse du site n\'est pas valide.',
            'exists' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'naf_code.regex' => 'Le code NAF doit être de la forme 5610A.',
            'idcc.regex' => 'L\'IDCC est un numéro de 4 chiffres (ex. 1979).',
            'country.size' => 'Indiquez le code pays sur 2 lettres (FR).',
            'country.alpha' => 'Indiquez le code pays sur 2 lettres (FR).',
            'enum' => 'Cette valeur n\'est pas valide.',
            'distinct' => 'Cette valeur est en double.',
            'uuid' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
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
