<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Quotes\LineInput;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteTotals;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/*
 * Review Focus 1, computed by hand (cents, round half up):
 *
 *   A  2,5 × 1 234,57 € − 10 %   TVA 20 %
 *      250 × 123457 × 9000 / 1 000 000 = 277 778,25  → 277 778  (2 777,78 €)
 *   B  3 × 333,33 € − 7,5 %      TVA 5,5 %
 *      300 × 33333 × 9250 / 1 000 000  = 92 499,075  → 92 499   (924,99 €)
 *   C  2,5 × 45,05 €             TVA 5,5 %
 *      250 × 4505 × 10000 / 1 000 000  = 11 262,5    → 11 263   (112,63 €, the half goes up)
 *
 *   HT          = 277 778 + 92 499 + 11 263 = 381 540                (3 815,40 €)
 *   TVA 20 %    = 277 778 × 0,20 = 55 555,6                → 55 556  (555,56 €)
 *   TVA 5,5 %   = (92 499 + 11 263) × 0,055 = 103 762 × 0,055
 *               = 5 706,91                                 → 5 707   (57,07 €)
 *               (per line it would be 5 087 + 619 = 5 706: one cent less)
 *   TVA         = 55 556 + 5 707 = 61 263                            (612,63 €)
 *   TTC         = 381 540 + 61 263 = 442 803                         (4 428,03 €)
 */
function qtFocusLines(): array
{
    return [
        new LineInput(quantity: '2.50', unitPrice: '1234.57', discountPercent: '10', vatRate: '20'),
        new LineInput(quantity: '3', unitPrice: '333.33', discountPercent: '7.5', vatRate: '5.5'),
        new LineInput(quantity: '2.5', unitPrice: '45.05', discountPercent: '0', vatRate: '5.50'),
    ];
}

it('quote totals are exact to the cent', function () {
    $totals = QuoteTotals::compute(qtFocusLines(), false);

    expect($totals->lines)->toBe([277778, 92499, 11263])
        ->and($totals->ht)->toBe(381540)
        ->and($totals->vatByRate)->toBe([
            ['rate' => '20.00', 'base' => 277778, 'vat' => 55556],
            ['rate' => '5.50', 'base' => 103762, 'vat' => 5707],
        ])
        ->and($totals->vat)->toBe(61263)
        ->and($totals->ttc)->toBe(442803)
        ->and(QuoteTotals::decimal($totals->ttc))->toBe('4428.03')
        ->and(QuoteTotals::display($totals->ttc))->toBe('4 428,03 €');

    // The same lines typed on the form give the same stored amounts.
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
    $this->actingAs(commercial());
    $company = Company::factory()->default()->create();
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));

    $this->post('/crm/quotes', quotePayload($opportunity, $company, [
        quoteLine(['label' => 'A', 'quantity' => '2,5', 'unit_price' => '1 234,57', 'discount_percent' => '10', 'vat_rate' => '20']),
        quoteLine(['label' => 'B', 'quantity' => '3', 'unit_price' => '333,33', 'discount_percent' => '7,5', 'vat_rate' => '5,5']),
        quoteLine(['label' => 'C', 'quantity' => '2,5', 'unit_price' => '45,05', 'discount_percent' => '', 'vat_rate' => '5,5']),
    ]))->assertSessionHasNoErrors();

    $quote = Quote::query()->sole();
    expect([$quote->total_ht, $quote->total_vat, $quote->total_ttc])->toBe(['3815.40', '612.63', '4428.03'])
        ->and($quote->lines()->pluck('total_ht')->all())->toBe(['2777.78', '924.99', '112.63'])
        ->and($quote->lines()->pluck('discount_percent')->all())->toBe(['10.00', '7.50', '0.00'])
        ->and($quote->lines()->pluck('vat_rate')->all())->toBe(['20.00', '5.50', '5.50']);

    $this->get("/crm/quotes/{$quote->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('quote.totals.ht', '3815.40')
            ->where('quote.totals.vat', '612.63')
            ->where('quote.totals.ttc', '4428.03')
            ->where('quote.totals.vat_by_rate', [
                ['rate' => '20.00', 'base' => '2777.78', 'vat' => '555.56'],
                ['rate' => '5.50', 'base' => '1037.62', 'vat' => '57.07'],
            ]));
});

it('rounds a discount and a quantity without any float drift', function () {
    // 0,1 + 0,2 style traps: 3 × 0,10 € − 33,33 % = 0,200010 → 0,20 €.
    $totals = QuoteTotals::compute([new LineInput('3', '0.10', '33.33', '20')], false);
    expect($totals->lines)->toBe([20])->and($totals->vat)->toBe(4)->and($totals->ttc)->toBe(24);

    // Large amounts stay exact (beyond a float's 15 significant digits):
    // 999 999 × 9 999 999 999 / 100 = 99 999 899 990 000,01 → 99 999 899 990 000 cents,
    // more than numeric(12,2) holds.
    $big = QuoteTotals::compute([new LineInput('9999.99', '99999999.99', '0', '20')], false);
    expect($big->ht)->toBe(99_999_899_990_000)
        ->and(QuoteTotals::decimal($big->ht))->toBe('999998999900.00')
        ->and($big->fits())->toBeFalse()
        ->and(QuoteTotals::compute(qtFocusLines(), false)->fits())->toBeTrue();
});

it('forces every rate to 0 for an exempt company', function () {
    $totals = QuoteTotals::compute(qtFocusLines(), true);

    expect($totals->ht)->toBe(381540)
        ->and($totals->vatByRate)->toBe([['rate' => '0.00', 'base' => 381540, 'vat' => 0]])
        ->and($totals->vat)->toBe(0)
        ->and($totals->ttc)->toBe(381540);
});
