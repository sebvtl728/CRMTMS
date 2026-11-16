<?php

namespace App\Modules\Crm\People;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Gdpr\LegalBasis;
use App\Modules\Crm\Organisations\OrganisationStatus;
use App\Modules\Crm\Support\ReferenceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of a person (create and update). Authorisation is done by the
 * route middleware (crm.fiches.update). Only the last name is required.
 */
class PersonRequest extends FormRequest
{
    private const TEXT = ['civility', 'first_name', 'last_name', 'email', 'email_secondary', 'phone', 'mobile', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'birth_date', 'project'];

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
     * '' as null, phones normalised, e-mails lower case, country upper case.
     * Only the keys present in $input are returned.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        $clean = [];

        foreach (self::TEXT as $key) {
            if (array_key_exists($key, $input)) {
                $value = is_string($input[$key]) ? trim($input[$key]) : $input[$key];
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        foreach (['phone', 'mobile'] as $key) {
            if (is_string($clean[$key] ?? null)) {
                $clean[$key] = PhoneNumber::normalise($clean[$key]);
            }
        }
        if (is_string($clean['country'] ?? null)) {
            $clean['country'] = mb_strtoupper($clean['country']);
        }
        foreach (['email', 'email_secondary'] as $key) {
            if (is_string($clean[$key] ?? null)) {
                $clean[$key] = mb_strtolower($clean[$key]);
            }
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
            'civility' => ['nullable', Rule::in(['M.', 'Mme'])],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'email_secondary' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'is_individual' => ['sometimes', 'boolean'],
            'project' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(OrganisationStatus::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->route('person');
        $current = $current instanceof Person ? $current : null;

        return [
            ...self::fieldRules(),
            'legal_basis' => ['sometimes', 'nullable', Rule::enum(LegalBasis::class)],
            'professional_situation_id' => ['nullable', ...ReferenceRules::item(ReferenceList::ProfessionalSituation, $current?->professional_situation_id)],
            'education_level_id' => ['nullable', ...ReferenceRules::item(ReferenceList::EducationLevel, $current?->education_level_id)],
            'funding_mode_id' => ['nullable', ...ReferenceRules::item(ReferenceList::FundingMode, $current?->funding_mode_id)],
            'origin_id' => ['nullable', ...ReferenceRules::item(ReferenceList::ContactOrigin, $current?->origin_id)],
            'responsible_user_id' => ['nullable', ...ReferenceRules::responsible($current?->responsible_user_id)],
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
            'exists' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'civility.in' => 'Choisissez M. ou Mme.',
            'country.size' => 'Indiquez le code pays sur 2 lettres (FR).',
            'country.alpha' => 'Indiquez le code pays sur 2 lettres (FR).',
            'birth_date.date_format' => 'La date de naissance doit être au format AAAA-MM-JJ.',
            'birth_date.before_or_equal' => 'La date de naissance ne peut pas être dans le futur.',
            'birth_date.after' => 'La date de naissance n\'est pas plausible.',
            'enum' => 'Cette valeur n\'est pas valide.',
            'distinct' => 'Cette valeur est en double.',
            'uuid' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'boolean' => 'Cette valeur n\'est pas valide.',
        ];
    }
}
