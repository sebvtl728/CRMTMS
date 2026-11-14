<?php

namespace App\Modules\Crm\Quotes;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityAccounts;
use App\Modules\Crm\Opportunities\OpportunityTrainings;
use Carbon\CarbonImmutable;

/**
 * Props of the quote form: prefilled from the opportunity on creation
 * (default company, its validity, one line per training of the opportunity
 * at the price of its target), the stored draft on edit.
 */
final class QuoteForm
{
    public function __construct(private readonly Trainings $trainings) {}

    /**
     * @return array<string, mixed>
     */
    public function create(Opportunity $opportunity): array
    {
        $company = Company::query()->where('is_default', true)->first() ?? Company::query()->orderBy('name')->first()
            ?? throw new UserFacingHttpException(409, 'Aucune société émettrice : créez-en une dans Administration › Sociétés.');
        $today = CarbonImmutable::now(QuoteNumbering::TIMEZONE)->startOfDay();
        $individual = $opportunity->isIndividual();
        $rate = $company->vat_regime === VatRegime::Exempt ? '0.00' : QuoteTotals::DEFAULT_VAT_RATE;

        $lines = [];
        $missing = [];
        foreach (OpportunityTrainings::stored($opportunity) as $stored) {
            $training = $this->trainings->find($stored['training_id']);
            if ($training === null) {
                continue;
            }
            $price = self::price($training, $individual);
            if ($price === null) {
                $missing[] = $training->title;
            }
            $lines[] = [
                'training_id' => $training->id,
                'label' => $training->title,
                'description' => null,
                'quantity' => $stored['quantity'],
                'unit' => 'stagiaire',
                'unit_price' => $price,
                'discount_percent' => '0.00',
                'vat_rate' => $rate,
            ];
        }

        return [
            'quote' => [
                'id' => null,
                'number' => null,
                'company_id' => $company->id,
                'contact_person_id' => $this->defaultContact($opportunity),
                'issued_on' => $today->toDateString(),
                'valid_until' => $today->addDays($company->quote_validity_days)->toDateString(),
                'notes' => null,
                'lines' => $lines,
            ],
            'missing_prices' => $missing,
            'vat_exempt' => $company->vat_regime === VatRegime::Exempt,
            'vat_regime_changed' => false,
            ...$this->shared($opportunity, []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function edit(Quote $quote, Opportunity $opportunity): array
    {
        $lines = $quote->lines()->get();

        return [
            'quote' => [
                'id' => $quote->id,
                'number' => $quote->number,
                'company_id' => $quote->company_id,
                'contact_person_id' => $quote->contact_person_id,
                'issued_on' => $quote->issued_on->toDateString(),
                'valid_until' => $quote->valid_until->toDateString(),
                'notes' => $quote->notes,
                'lines' => $lines->map(fn (QuoteLine $l) => [
                    'training_id' => $l->training_id,
                    'label' => $l->label,
                    'description' => $l->description,
                    'quantity' => $l->quantity,
                    'unit' => $l->unit,
                    'unit_price' => $l->unit_price,
                    'discount_percent' => $l->discount_percent,
                    'vat_rate' => $l->vat_rate,
                ])->values()->all(),
            ],
            'missing_prices' => [],
            // A draft follows its company: the rates apply as it is now.
            'vat_exempt' => $quote->company?->vat_regime === VatRegime::Exempt,
            // Saved under the other regime: the user checks the rates (exempt → liable: lines at 0 %).
            'vat_regime_changed' => QuoteTransitions::vatRegimeChanged($quote),
            ...$this->shared($opportunity, $lines->pluck('training_id')->filter()->values()->all(), $quote),
        ];
    }

    /**
     * @param  list<string>  $kept  trainings already on the quote (kept among the choices when inactive)
     * @return array<string, mixed>
     */
    private function shared(Opportunity $opportunity, array $kept, ?Quote $quote = null): array
    {
        $contacts = OpportunityAccounts::contactOptions($opportunity->organisation_id, $opportunity->person_id);
        if ($quote?->contact_person_id !== null && ! in_array($quote->contact_person_id, array_column($contacts, 'value'), true) && $quote->contact !== null) {
            $contacts[] = ['value' => $quote->contact->id, 'label' => $quote->contact->fullName()];
        }

        return [
            'opportunity' => [
                'id' => $opportunity->id,
                'title' => $opportunity->title,
                'account' => $opportunity->isIndividual()
                    ? ['type' => 'person', 'id' => $opportunity->person_id, 'name' => $opportunity->person?->fullName()]
                    : ['type' => 'organisation', 'id' => $opportunity->organisation_id, 'name' => $opportunity->organisation?->name],
            ],
            'options' => [
                'companies' => Company::query()->orderByDesc('is_default')->orderBy('name')->get()
                    ->map(fn (Company $c) => [
                        'value' => $c->id,
                        'label' => $c->name,
                        'vat_exempt' => $c->vat_regime === VatRegime::Exempt,
                        'validity_days' => $c->quote_validity_days,
                    ])->values()->all(),
                'contacts' => $contacts,
                'units' => QuoteLine::unitOptions(),
                'vat_rates' => array_map(fn (string $r) => ['value' => $r, 'label' => str_replace('.', ',', rtrim(rtrim($r, '0'), '.')).' %'], QuoteTotals::VAT_RATES),
                'training' => $this->trainingOptions($kept, $opportunity->isIndividual()),
            ],
        ];
    }

    /**
     * The particulier himself; for an entreprise, the first interlocuteur of
     * the opportunity still active there.
     */
    private function defaultContact(Opportunity $opportunity): ?string
    {
        if ($opportunity->person_id !== null) {
            return $opportunity->person_id;
        }
        $active = array_column(OpportunityAccounts::contactOptions($opportunity->organisation_id, null), 'value');
        $linked = $opportunity->contacts()->pluck('people.id')->all();

        return collect($linked)->first(fn (string $id) => in_array($id, $active, true));
    }

    /**
     * Active trainings (and the inactive ones the quote has), with their
     * price for the target of the opportunity.
     *
     * @param  list<string>  $kept
     * @return list<array{value: string, label: string, das_id: string, unit_price: ?string, is_active: bool}>
     */
    private function trainingOptions(array $kept, bool $individual): array
    {
        $all = $this->trainings->options();
        $ids = array_map(fn (TrainingData $t) => $t->id, $all);
        foreach (array_unique($kept) as $id) {
            $training = in_array($id, $ids, true) ? null : $this->trainings->find($id);
            if ($training !== null) {
                $all[] = $training;
            }
        }

        return array_map(fn (TrainingData $t) => [
            'value' => $t->id,
            'label' => ($t->code === null ? $t->title : "{$t->title} ({$t->code})").($t->is_active ? '' : ' (inactive)'),
            'title' => $t->title,
            'das_id' => $t->das_id,
            'unit_price' => self::price($t, $individual),
            'is_active' => $t->is_active,
        ], $all);
    }

    private static function price(TrainingData $training, bool $individual): ?string
    {
        $cents = $individual ? $training->price_individual_cents : $training->price_company_cents;

        return $cents === null ? null : QuoteTotals::decimal($cents);
    }
}
