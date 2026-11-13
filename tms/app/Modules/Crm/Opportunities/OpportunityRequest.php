<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\PersonRequest;
use App\Modules\Crm\Support\ReferenceRules;
use App\Modules\Crm\Visibility\CrmVisibility;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation of an opportunity (create and update). Authorisation is done by
 * the route middleware (crm.create / crm.update) and the policy. The account
 * (entreprise or particulier) and the stage are chosen at creation only: the
 * stage then moves through POST …/stage (rules and history).
 */
class OpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['title', 'expected_close_on', 'organisation_id', 'person_id', 'probability'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $value = is_string($value) ? trim($value) : $value;
                $clean[$key] = $value === '' ? null : $value;
            }
        }
        if ($this->has('amount')) {
            $clean['amount'] = Amount::parse($this->input('amount'));
        }

        $this->merge($clean);
    }

    private function current(): ?Opportunity
    {
        $current = $this->route('opportunity');

        return $current instanceof Opportunity ? $current : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->current();
        $user = $this->user();
        assert($user instanceof User);

        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'das_id' => ['required', ...ReferenceRules::item(ReferenceList::Das, $current?->das_id), $this->followedDas($user, $current?->das_id)],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'expected_close_on' => ['nullable', 'date_format:Y-m-d'],
            'priority_id' => ['nullable', ...ReferenceRules::item(ReferenceList::Priority, $current?->priority_id)],
            'funding_mode_id' => ['nullable', ...ReferenceRules::item(ReferenceList::FundingMode, $current?->funding_mode_id)],
            'responsible_user_id' => ['nullable', ...ReferenceRules::responsible($current?->responsible_user_id)],
            'contact_ids' => ['sometimes', 'array'],
            'contact_ids.*' => ['distinct', Rule::in($this->allowedContacts($user))],
        ];

        if ($current === null) {
            $rules['organisation_id'] = ['nullable', 'bail', 'uuid', $this->visible(fn (string $id) => OpportunityAccounts::organisation($id, $user) !== null, 'Cette entreprise n\'existe pas ou n\'est plus disponible.')];
            $rules['person_id'] = ['nullable', 'bail', 'uuid', $this->visible(fn (string $id) => OpportunityAccounts::person($id, $user) !== null, 'Cette personne n\'existe pas ou n\'est plus disponible.')];
            $rules['stage_id'] = ['required', ...ReferenceRules::item(ReferenceList::PipelineStage), function (string $attribute, mixed $value, Closure $fail): void {
                $stage = ReferenceItem::query()->find($value);
                if ($stage !== null && OpportunityStage::outcome($stage) !== null) {
                    $fail('Créez l\'opportunité dans une étape en cours, puis passez-la à Gagné ou Perdu.');
                }
            }];
        }

        return $rules;
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        if ($this->current() !== null) {
            return [];
        }

        return [function (Validator $validator): void {
            if (($this->input('organisation_id') === null) === ($this->input('person_id') === null)) {
                $validator->errors()->add('account', 'Choisissez une entreprise ou un particulier.');
            }
        }];
    }

    /**
     * A Responsable DAS (granted crm.create / crm.update) only works in the DAS he follows.
     */
    private function followedDas(User $user, ?string $keep): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($user, $keep): void {
            if (CrmVisibility::restricts($user) && $value !== $keep && ! in_array($value, CrmVisibility::followedDas($user), true)) {
                $fail('Vous ne suivez pas ce DAS.');
            }
        };
    }

    /**
     * @param  Closure(string): bool  $exists
     */
    private function visible(Closure $exists, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($exists, $message): void {
            if (! is_string($value) || ! $exists($value)) {
                $fail($message);
            }
        };
    }

    /**
     * @return list<string>
     */
    private function allowedContacts(User $user): array
    {
        $current = $this->current();
        if ($current !== null) {
            return array_column(OpportunityAccounts::contactOptions($current->organisation_id, $current->person_id), 'value');
        }

        $organisation = $this->input('organisation_id');
        $person = $this->input('person_id');
        $organisation = is_string($organisation) && Str::isUuid($organisation) ? OpportunityAccounts::organisation($organisation, $user)?->id : null;
        $person = is_string($person) && Str::isUuid($person) ? OpportunityAccounts::person($person, $user)?->id : null;

        return array_column(OpportunityAccounts::contactOptions($organisation, $person), 'value');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...PersonRequest::validationMessages(),
            'title.max' => 'L\'objet ne doit pas dépasser 200 caractères.',
            'numeric' => 'Indiquez un montant, par exemple 12 500,00.',
            'decimal' => 'Indiquez au plus deux décimales.',
            'amount.min' => 'Le montant ne peut pas être négatif.',
            'amount.max' => 'Le montant est trop élevé.',
            'integer' => 'Indiquez un nombre entier.',
            'between' => 'Indiquez une valeur entre 0 et 100.',
            'expected_close_on.date_format' => 'La date doit être au format AAAA-MM-JJ.',
            'array' => 'Cette valeur n\'est pas valide.',
            'contact_ids.*.in' => 'Cette personne n\'est pas un interlocuteur de ce compte.',
        ];
    }
}
