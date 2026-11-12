<?php

namespace App\Modules\Crm\Affiliations;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\People\PhoneNumber;
use App\Modules\Crm\Support\ReferenceRules;
use App\Modules\Crm\Visibility\CrmVisibility;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of an affiliation. POST (from an entreprise): either an
 * existing person (person_id) or a new one (last_name...), plus the
 * affiliation fields. PUT: only the affiliation fields, each optional so that
 * « Mettre fin » can send ended_on alone.
 */
class AffiliationRequest extends FormRequest
{
    public const ALREADY_ATTACHED = 'Cette personne est déjà rattachée à cette entreprise.';

    public const ALREADY_ACTIVE = 'Cette personne est déjà rattachée à cette entreprise : un seul rattachement peut être actif.';

    private const TEXT = ['civility', 'first_name', 'last_name', 'job_title', 'work_email', 'work_phone', 'started_on', 'ended_on'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (self::TEXT as $key) {
            if ($this->has($key)) {
                $value = is_string($this->input($key)) ? trim($this->input($key)) : $this->input($key);
                $clean[$key] = $value === '' ? null : $value;
            }
        }
        if (is_string($clean['work_phone'] ?? null)) {
            $clean['work_phone'] = PhoneNumber::normalise($clean['work_phone']);
        }
        if (is_string($clean['work_email'] ?? null)) {
            $clean['work_email'] = mb_strtolower($clean['work_email']);
        }

        $this->merge($clean);
    }

    private function creating(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->route('affiliation');
        $current = $current instanceof Affiliation ? $current : null;
        $optional = $this->creating() ? 'nullable' : 'sometimes';

        $rules = [
            'job_title' => [$optional, 'nullable', 'string', 'max:255'],
            'decision_role_id' => [$optional, 'nullable', ...ReferenceRules::item(ReferenceList::DecisionRole, $current?->decision_role_id)],
            'work_email' => [$optional, 'nullable', 'string', 'email', 'max:255'],
            'work_phone' => [$optional, 'nullable', 'string', 'max:30'],
            'is_referent' => ['sometimes', 'boolean'],
            'started_on' => [$optional, 'nullable', 'date_format:Y-m-d', 'after:1900-01-01'],
            'ended_on' => [$optional, 'nullable', 'date_format:Y-m-d', 'after:1900-01-01'],
        ];

        if ($this->creating()) {
            $rules += [
                'person_id' => ['nullable', 'uuid', Rule::exists('people', 'id')->where('tenant_id', app(TenantContext::class)->id())->whereNull('deleted_at'), $this->visiblePerson()],
                'last_name' => ['required_without:person_id', 'nullable', 'string', 'max:255'],
                'first_name' => ['nullable', 'string', 'max:255'],
                'civility' => ['nullable', Rule::in(['M.', 'Mme'])],
            ];
            $rules['ended_on'][] = 'after_or_equal:started_on';
        }

        return $rules;
    }

    /** The person must be one the user is allowed to see. */
    private function visiblePerson(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $user = $this->user();

            if (! $user instanceof User || ! is_string($value)) {
                return;
            }

            if (! CrmVisibility::people(Person::query(), $user)->whereKey($value)->exists()) {
                $fail('Cette valeur n\'existe pas ou n\'est plus disponible.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required_without' => 'Indiquez le nom de la personne.',
            'max' => 'Ce champ est trop long.',
            'email' => 'L\'adresse e-mail n\'est pas valide.',
            'exists' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'civility.in' => 'Choisissez M. ou Mme.',
            'date_format' => 'La date doit être au format AAAA-MM-JJ.',
            'after' => 'La date n\'est pas plausible.',
            'ended_on.after_or_equal' => 'La fin du rattachement ne peut pas précéder son début.',
            'boolean' => 'Cette valeur n\'est pas valide.',
            'uuid' => 'Cette valeur n\'est pas valide.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->creating()) {
                $organisation = $this->route('organisation');
                $personId = $this->input('person_id');

                if ($organisation instanceof Organisation && is_string($personId) && self::activeExists($personId, $organisation->getKey())) {
                    $validator->errors()->add('person_id', self::ALREADY_ATTACHED);
                }

                return;
            }

            $affiliation = $this->route('affiliation');

            if (! $affiliation instanceof Affiliation) {
                return;
            }

            // Effective dates after the update.
            $started = $this->has('started_on') ? $this->input('started_on') : $affiliation->started_on?->toDateString();
            $ended = $this->has('ended_on') ? $this->input('ended_on') : $affiliation->ended_on?->toDateString();

            if (is_string($started) && is_string($ended) && $ended < $started) {
                $validator->errors()->add('ended_on', 'La fin du rattachement ne peut pas précéder son début.');
            }

            // Re-opening an ended affiliation while another one is active.
            if ($ended === null && $affiliation->ended_on !== null && self::activeExists($affiliation->person_id, $affiliation->organisation_id, $affiliation->getKey())) {
                $validator->errors()->add('ended_on', self::ALREADY_ACTIVE);
            }
        }];
    }

    private static function activeExists(string $personId, string $organisationId, ?string $except = null): bool
    {
        return Affiliation::query()
            ->where('person_id', $personId)->where('organisation_id', $organisationId)->whereNull('ended_on')
            ->when($except, fn ($q, $id) => $q->whereKeyNot($id))
            ->exists();
    }
}
