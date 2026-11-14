<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Crm\Opportunities\Opportunity;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a draft quote: its number (at creation, in the same transaction),
 * its lines and its totals (QuoteTotals) together. The account is copied
 * from the opportunity; the VAT regime follows the company while the quote
 * is a draft (frozen when it is sent).
 *
 * Lines are normalised and validated (QuoteRequest): decimal strings with
 * two decimals at most, a unit of QuoteLine::UNITS, a rate of
 * QuoteTotals::VAT_RATES.
 *
 * @phpstan-type LineData array{training_id: ?string, label: string, description: ?string, quantity: string, unit: string, unit_price: string, discount_percent: string, vat_rate: string}
 * @phpstan-type QuoteData array{contact_person_id: ?string, issued_on: string, valid_until: string, notes: ?string, lines: list<LineData>}
 */
final class QuoteWriter
{
    public function __construct(
        private readonly QuoteNumbering $numbering,
        private readonly Trainings $trainings,
    ) {}

    /**
     * @param  QuoteData  $data
     */
    public function create(Opportunity $opportunity, Company $company, array $data): Quote
    {
        try {
            return DB::transaction(function () use ($opportunity, $company, $data): Quote {
                $quote = new Quote([
                    'company_id' => $company->id,
                    'opportunity_id' => $opportunity->id,
                    'organisation_id' => $opportunity->organisation_id,
                    'person_id' => $opportunity->person_id,
                ]);
                $quote->number = $this->numbering->next($company, CarbonImmutable::now());
                $quote->status = QuoteStatus::Draft;
                $quote->setRelation('company', $company);

                return $this->write($quote, $data);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'quotes_tenant_id_number_unique')) {
                throw $e;
            }

            throw ValidationException::withMessages(['company_id' => 'Ce numéro de devis est déjà pris par une autre société de même préfixe : donnez à chaque société un préfixe différent (Administration › Sociétés).']);
        }
    }

    /**
     * @param  QuoteData  $data
     */
    public function update(Quote $quote, array $data): Quote
    {
        return DB::transaction(fn (): Quote => $this->write($quote, $data));
    }

    /**
     * A new draft with the content of $source: new number, issued today,
     * valid for the company's usual duration.
     */
    public function duplicate(Quote $source, Opportunity $opportunity): Quote
    {
        $company = $source->company;
        if ($company === null || $company->trashed()) {
            $company = Company::query()->where('is_default', true)->firstOrFail();
        }
        $today = CarbonImmutable::now(QuoteNumbering::TIMEZONE)->startOfDay();

        return $this->create($opportunity, $company, [
            'contact_person_id' => $source->contact_person_id,
            'issued_on' => $today->toDateString(),
            'valid_until' => $today->addDays($company->quote_validity_days)->toDateString(),
            'notes' => $source->notes,
            'lines' => $source->lines()->get()->map(fn (QuoteLine $l) => [
                'training_id' => $l->training_id,
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit' => $l->unit,
                'unit_price' => $l->unit_price,
                'discount_percent' => $l->discount_percent,
                'vat_rate' => $l->vat_rate,
            ])->values()->all(),
        ]);
    }

    /**
     * @param  QuoteData  $data
     */
    private function write(Quote $quote, array $data): Quote
    {
        $company = $quote->company;
        $exempt = $company !== null && $company->vat_regime === VatRegime::Exempt;
        $lines = array_map(fn (array $l) => [...$l, 'vat_rate' => $exempt ? '0.00' : $l['vat_rate']], $data['lines']);

        $totals = QuoteTotals::compute(array_map(
            fn (array $l) => new LineInput($l['quantity'], $l['unit_price'], $l['discount_percent'], $l['vat_rate']),
            $lines,
        ), $exempt);
        if (! $totals->fits()) {
            throw ValidationException::withMessages(['lines' => 'Le total du devis est trop élevé.']);
        }

        $quote->fill([
            'contact_person_id' => $data['contact_person_id'],
            'issued_on' => $data['issued_on'],
            'valid_until' => $data['valid_until'],
            'notes' => $data['notes'],
        ]);
        $quote->forceFill([
            'vat_exempt' => $exempt,
            'total_ht' => QuoteTotals::decimal($totals->ht),
            'total_vat' => QuoteTotals::decimal($totals->vat),
            'total_ttc' => QuoteTotals::decimal($totals->ttc),
        ])->save();

        $existing = $quote->lines()->get()->values();
        foreach ($lines as $position => $data) {
            $line = $existing->get($position) ?? new QuoteLine(['quote_id' => $quote->id]);
            $line->fill([
                'position' => $position,
                'training_id' => $data['training_id'],
                'das_id' => $data['training_id'] === null ? null : $this->trainings->find($data['training_id'])?->das_id,
                'label' => $data['label'],
                'description' => $data['description'],
                'quantity' => $data['quantity'],
                'unit' => $data['unit'],
                'unit_price' => $data['unit_price'],
                'discount_percent' => $data['discount_percent'],
                'vat_rate' => $data['vat_rate'],
                'total_ht' => QuoteTotals::decimal($totals->lines[$position]),
            ])->save();
        }
        $existing->slice(count($lines))->each(fn (QuoteLine $l) => $l->delete());

        return $quote->unsetRelation('lines');
    }
}
