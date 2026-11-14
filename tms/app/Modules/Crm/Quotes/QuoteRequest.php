<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Amount;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityAccounts;
use App\Modules\Crm\People\PersonRequest;
use App\Modules\Crm\Visibility\CrmVisibility;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation of a draft quote (create and update), typed the French way
 * (« 12 500,00 », « 2,5 », « 5,5 »). The opportunity and the company are
 * chosen at creation only. Rights: route middleware and QuotePolicy.
 *
 * @phpstan-import-type QuoteData from QuoteWriter
 */
class QuoteRequest extends FormRequest
{
    public const MAX_LINES = 100;

    private const DECIMAL = '/^\d+(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /** The quote being edited (null: a creation). */
    public function current(): ?Quote
    {
        $quote = $this->route('quote');

        return $quote instanceof Quote ? $quote : null;
    }

    /** The opportunity of the quote: the edited one's, or the visible one chosen. */
    public function opportunity(): ?Opportunity
    {
        $current = $this->current();
        if ($current !== null) {
            return $current->opportunity;
        }
        $id = $this->input('opportunity_id');
        $user = $this->user();

        return is_string($id) && Str::isUuid($id) && $user instanceof User
            ? CrmVisibility::opportunities(Opportunity::query(), $user)->with(['organisation', 'person'])->find($id)
            : null;
    }

    /** The issuing company: the edited quote's, or the one chosen. */
    public function company(): ?Company
    {
        $current = $this->current();
        if ($current !== null) {
            return $current->company;
        }
        $id = $this->input('company_id');

        return is_string($id) && Str::isUuid($id) ? Company::query()->find($id) : null;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['opportunity_id', 'company_id', 'contact_person_id', 'issued_on', 'valid_until', 'notes'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $value = is_string($value) ? trim($value) : $value;
                $clean[$key] = $value === '' ? null : $value;
            }
        }
        $this->merge($clean);

        if ($this->has('lines')) {
            $exempt = $this->company()?->vat_regime === VatRegime::Exempt;
            $this->merge(['lines' => self::normaliseLines($this->input('lines'), $exempt)]);
        }
    }

    /**
     * French typing of the lines; an exempt company puts every line at 0 %.
     */
    public static function normaliseLines(mixed $lines, bool $exempt): mixed
    {
        if (! is_array($lines)) {
            return $lines;
        }

        return array_map(function (mixed $line) use ($exempt): mixed {
            if (! is_array($line)) {
                return $line;
            }
            $clean = fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            $discount = Amount::parse($line['discount_percent'] ?? null);
            $rate = $line['vat_rate'] ?? null;

            return [
                'training_id' => $clean($line['training_id'] ?? null),
                'label' => $clean($line['label'] ?? null),
                'description' => $clean($line['description'] ?? null),
                'quantity' => Amount::parse($line['quantity'] ?? null),
                'unit' => $line['unit'] ?? null,
                'unit_price' => Amount::parse($line['unit_price'] ?? null),
                'discount_percent' => $discount === null ? '0' : $discount,
                'vat_rate' => $exempt ? '0.00' : (QuoteTotals::normaliseRate($rate) ?? $rate),
            ];
        }, $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->current();
        $kept = $current === null ? [] : $current->lines()->whereNotNull('training_id')->pluck('training_id')->all();

        $rules = [
            'contact_person_id' => ['nullable', 'bail', 'uuid', Rule::in($this->allowedContacts())],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['array'],
            'lines.*.training_id' => ['nullable', 'bail', 'string', $this->available($kept)],
            'lines.*.label' => ['required', 'string', 'max:300'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['bail', 'required', 'string', 'regex:'.self::DECIMAL, 'numeric', 'min:0.01', 'max:9999.99'],
            'lines.*.unit' => ['required', Rule::in(array_keys(QuoteLine::UNITS))],
            'lines.*.unit_price' => ['bail', 'required', 'string', 'regex:'.self::DECIMAL, 'numeric', 'max:9999999999.99'],
            'lines.*.discount_percent' => ['bail', 'required', 'string', 'regex:'.self::DECIMAL, 'numeric', 'max:100'],
            'lines.*.vat_rate' => ['required', Rule::in(QuoteTotals::VAT_RATES)],
        ];

        if ($current === null) {
            $rules['opportunity_id'] = ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->opportunity() === null) {
                    $fail('Cette opportunité n\'existe pas ou n\'est plus disponible.');
                }
            }];
            $rules['company_id'] = ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->company() === null) {
                    $fail('Choisissez une société émettrice.');
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
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            /** @var list<array{quantity: string, unit_price: string, discount_percent: string, vat_rate: string}> $lines */
            $lines = $this->input('lines');
            $totals = QuoteTotals::compute(array_map(fn (array $l) => new LineInput($l['quantity'], $l['unit_price'], $l['discount_percent'], $l['vat_rate']), $lines), false);
            if (! $totals->fits()) {
                $validator->errors()->add('lines', 'Le total du devis est trop élevé.');
            }
        }];
    }

    /**
     * The validated quote, as QuoteWriter takes it.
     *
     * @return QuoteData
     */
    public function quoteData(): array
    {
        $v = $this->validated();

        return [
            'contact_person_id' => $v['contact_person_id'] ?? null,
            'issued_on' => $v['issued_on'],
            'valid_until' => $v['valid_until'],
            'notes' => $v['notes'] ?? null,
            'lines' => array_values(array_map(fn (array $l) => [
                'training_id' => $l['training_id'] ?? null,
                'label' => $l['label'],
                'description' => $l['description'] ?? null,
                'quantity' => $l['quantity'],
                'unit' => $l['unit'],
                'unit_price' => $l['unit_price'],
                'discount_percent' => $l['discount_percent'],
                'vat_rate' => $l['vat_rate'],
            ], $v['lines'])),
        ];
    }

    /**
     * The recipient: an active interlocuteur of the entreprise or the
     * particulier himself; the one already chosen stays valid.
     *
     * @return list<string>
     */
    private function allowedContacts(): array
    {
        $opportunity = $this->opportunity();
        $allowed = $opportunity === null ? [] : array_column(OpportunityAccounts::contactOptions($opportunity->organisation_id, $opportunity->person_id), 'value');
        $kept = $this->current()?->contact_person_id;

        return $kept === null ? $allowed : [...$allowed, $kept];
    }

    /**
     * A training of the catalogue; an inactive one only if the quote already has it.
     *
     * @param  list<string>  $kept
     */
    private function available(array $kept): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kept): void {
            $training = is_string($value) && Str::isUuid($value) ? app(Trainings::class)->find($value) : null;
            if ($training === null) {
                $fail('Cette formation n\'existe pas.');
            } elseif (! $training->is_active && ! in_array($training->id, $kept, true)) {
                $fail('Cette formation est désactivée : choisissez-en une autre.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...PersonRequest::validationMessages(),
            'array' => 'Cette valeur n\'est pas valide.',
            'contact_person_id.in' => 'Cette personne n\'est pas un interlocuteur de ce compte.',
            'issued_on.required' => 'Indiquez la date du devis.',
            'issued_on.date_format' => 'La date doit être au format AAAA-MM-JJ.',
            'valid_until.required' => 'Indiquez la date de validité.',
            'valid_until.date_format' => 'La date doit être au format AAAA-MM-JJ.',
            'valid_until.after_or_equal' => 'La date de validité doit suivre la date du devis.',
            'notes.max' => 'Les mentions particulières ne doivent pas dépasser 5 000 caractères.',
            'lines.required' => 'Ajoutez au moins une ligne au devis.',
            'lines.min' => 'Ajoutez au moins une ligne au devis.',
            'lines.max' => 'Un devis compte au plus '.self::MAX_LINES.' lignes.',
            'lines.*.label.required' => 'Indiquez un libellé.',
            'lines.*.label.max' => 'Le libellé ne doit pas dépasser 300 caractères.',
            'lines.*.description.max' => 'La description ne doit pas dépasser 2 000 caractères.',
            'lines.*.quantity.required' => 'Indiquez une quantité.',
            'lines.*.quantity.string' => 'Indiquez une quantité, par exemple 1 ou 2,5.',
            'lines.*.quantity.regex' => 'Indiquez une quantité, par exemple 1 ou 2,5.',
            'lines.*.quantity.numeric' => 'Indiquez une quantité, par exemple 1 ou 2,5.',
            'lines.*.quantity.min' => 'La quantité doit être supérieure à 0.',
            'lines.*.quantity.max' => 'La quantité ne peut pas dépasser 9 999,99.',
            'lines.*.unit.required' => 'Choisissez une unité.',
            'lines.*.unit.in' => 'Choisissez une unité de la liste.',
            'lines.*.unit_price.required' => 'Indiquez un prix unitaire.',
            'lines.*.unit_price.string' => 'Indiquez un montant, par exemple 12 500,00.',
            'lines.*.unit_price.regex' => 'Indiquez un montant, par exemple 12 500,00.',
            'lines.*.unit_price.numeric' => 'Indiquez un montant, par exemple 12 500,00.',
            'lines.*.unit_price.max' => 'Le prix unitaire est trop élevé.',
            'lines.*.discount_percent.required' => 'La remise doit être comprise entre 0 et 100 %.',
            'lines.*.discount_percent.string' => 'La remise doit être comprise entre 0 et 100 %.',
            'lines.*.discount_percent.regex' => 'La remise doit être comprise entre 0 et 100 %.',
            'lines.*.discount_percent.numeric' => 'La remise doit être comprise entre 0 et 100 %.',
            'lines.*.discount_percent.max' => 'La remise doit être comprise entre 0 et 100 %.',
            'lines.*.vat_rate.required' => 'Choisissez un taux de TVA.',
            'lines.*.vat_rate.in' => 'Choisissez un taux de TVA de la liste.',
        ];
    }
}
