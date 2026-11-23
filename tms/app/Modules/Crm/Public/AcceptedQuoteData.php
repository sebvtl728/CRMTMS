<?php

namespace App\Modules\Crm\Public;

/**
 * An accepted quote as another module reads it (creation of a dossier):
 * amounts in integer cents, quantities and discounts as « 12.50 » strings.
 */
final readonly class AcceptedQuoteData
{
    /**
     * @param  array{type: 'organisation'|'person', id: string, label: string}  $account
     * @param  ?string  $funding_mode_id  planned funding (« Modes de financement »): the quote's, else its opportunity's
     * @param  list<array{training_id: ?string, das_id: ?string, label: string, quantity: string, unit: string, unit_price_cents: int, discount_percent: string, total_ht_cents: int}>  $lines
     */
    public function __construct(
        public string $id,
        public string $number,
        public string $company_id,
        public array $account,
        public ?string $contact_person_id,
        public string $opportunity_id,
        public string $das_id,
        public array $lines,
        public ?string $funding_mode_id = null,
    ) {}
}
