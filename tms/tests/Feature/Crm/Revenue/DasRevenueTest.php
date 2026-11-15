<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Revenue\DasRevenue;
use App\Modules\Crm\Revenue\Period;
use App\Modules\Crm\Revenue\RevenueSources;
use App\Modules\Crm\Revenue\RevenueTargets;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function rvLine(string $price, ?string $label = null): array
{
    return [
        'training_id' => null, 'label' => $label ?? 'Formation', 'description' => null, 'quantity' => '1.00',
        'unit' => 'stagiaire', 'unit_price' => $price, 'discount_percent' => '0.00', 'vat_rate' => '20.00',
    ];
}

/**
 * A quote of $lines (price strings), accepted on $on; $dasIds sets the DAS of
 * each line (null = none).
 *
 * @param  list<string>  $prices
 * @param  list<string|null>  $dasIds
 * @param  array<string, mixed>  $opportunity
 */
function rvQuote(array $prices, ?string $on = '2026-11-10', array $dasIds = [], array $opportunity = [], string $status = 'accepted'): Quote
{
    $org = Organisation::query()->firstOrCreate(['name' => 'Le Bistrot']);
    $quote = makeQuote(makeOpportunity($org, $opportunity), null, array_map(fn ($p) => rvLine($p), $prices));
    foreach ($quote->lines()->orderBy('position')->get() as $i => $line) {
        DB::table('quote_lines')->where('id', $line->id)->update(['das_id' => $dasIds[$i] ?? null]);
    }
    $quote->forceFill(['status' => QuoteStatus::from($status), 'accepted_on' => $status === 'accepted' ? $on : null])->save();

    return $quote;
}

function rvWon(?string $amount, string $on = '2026-11-12', array $attributes = []): Opportunity
{
    $org = Organisation::query()->firstOrCreate(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['amount' => $amount, ...$attributes]);
    $opportunity->forceFill(['stage_id' => pipelineStage('Gagné')->id, 'won_on' => $on])->save();

    return $opportunity;
}

function rvPeriod(): Period
{
    return Period::of('month', '2026-11');
}

/** @return array<string, array<string, mixed>> rows by DAS label */
function rvRows($user, ?Period $period = null): array
{
    return collect(DasRevenue::for($user, $period ?? rvPeriod())['rows'])->keyBy('das_label')->all();
}

it('splits an accepted quote by the DAS of its lines', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    rvQuote(['1000.00', '250.50'], dasIds: [$resto->id, $supports->id]);

    $result = DasRevenue::for(direction(), rvPeriod());
    $rows = collect($result['rows'])->keyBy('das_label');
    expect($rows['Hôtellerie-restauration']['quotes_cents'])->toBe(100000)
        ->and($rows['Métiers supports']['quotes_cents'])->toBe(25050)
        ->and($rows['Métiers supports']['total_cents'])->toBe(25050)
        ->and($result['totals'])->toBe(['quotes_cents' => 125050, 'opportunities_cents' => 0, 'total_cents' => 125050, 'target_cents' => null, 'targeted_total_cents' => 0, 'rate' => null]);
});

it('a line without DAS uses the DAS of the opportunity', function () {
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    rvQuote(['400.00'], opportunity: ['das_id' => $supports->id]);

    expect(rvRows(direction())['Métiers supports']['quotes_cents'])->toBe(40000);
});

it('a won opportunity with an accepted quote is counted once, through the quote', function () {
    $quote = rvQuote(['1000.00'], '2026-11-10');
    $quote->opportunity->forceFill(['stage_id' => pipelineStage('Gagné')->id, 'won_on' => '2026-11-12', 'amount' => '9999.00'])->save();

    $result = DasRevenue::for(direction(), rvPeriod());
    expect($result['totals'])->toMatchArray(['quotes_cents' => 100000, 'opportunities_cents' => 0, 'total_cents' => 100000]);
});

it('a won opportunity without quote uses its amount, a null amount is ignored', function () {
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    rvWon('3000.00', attributes: ['das_id' => $supports->id]);
    rvWon(null);
    // Not won: ignored.
    makeOpportunity(Organisation::query()->firstOrCreate(['name' => 'Le Bistrot']), ['amount' => '500.00']);

    $result = DasRevenue::for(direction(), rvPeriod());
    $rows = collect($result['rows'])->keyBy('das_label');
    expect($rows['Métiers supports']['opportunities_cents'])->toBe(300000)
        ->and($result['totals'])->toMatchArray(['quotes_cents' => 0, 'opportunities_cents' => 300000, 'total_cents' => 300000]);
});

it('a won opportunity whose only accepted quote is trashed counts by its amount', function () {
    $quote = rvQuote(['1000.00']);
    $quote->opportunity->forceFill(['stage_id' => pipelineStage('Gagné')->id, 'won_on' => '2026-11-12', 'amount' => '700.00'])->save();
    $quote->delete();

    expect(DasRevenue::for(direction(), rvPeriod())['totals'])->toMatchArray(['quotes_cents' => 0, 'opportunities_cents' => 70000]);
});

it('excludes a trashed quote or opportunity', function () {
    $trashedQuote = rvQuote(['100.00']);
    $trashedQuote->delete();
    $trashedOpportunity = rvQuote(['200.00']);
    $trashedOpportunity->opportunity->delete();
    rvWon('300.00')->delete();

    expect(DasRevenue::for(direction(), rvPeriod())['totals']['total_cents'])->toBe(0);
});

it('excludes a quote accepted outside the period and an opportunity won outside it', function () {
    rvQuote(['100.00'], '2026-10-31');
    rvQuote(['200.00'], '2026-12-01');
    rvQuote(['300.00'], '2026-11-30'); // last day, counted
    rvQuote(['400.00'], '2026-11-01'); // first day, counted
    rvWon('500.00', '2026-10-31');
    rvWon('600.00', '2026-12-01');

    expect(DasRevenue::for(direction(), rvPeriod())['totals'])->toMatchArray(['quotes_cents' => 70000, 'opportunities_cents' => 0]);
    // The year and the quarter hold them all.
    expect(DasRevenue::for(direction(), Period::of('year', '2026'))['totals']['total_cents'])->toBe(210000);
    expect(DasRevenue::for(direction(), Period::of('quarter', '2026-T4'))['totals']['total_cents'])->toBe(210000);
});

it('excludes refused, sent, reminded, expired and draft quotes', function () {
    foreach (['refused', 'sent', 'reminded', 'expired', 'draft'] as $status) {
        rvQuote(['100.00'], status: $status);
    }

    expect(DasRevenue::for(direction(), rvPeriod())['totals']['total_cents'])->toBe(0);
});

it('das scope shows only the followed DAS, computed on all the quotes of the organisme', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    // Quotes created by someone else, on a DAS the responsable follows.
    rvQuote(['1000.00'], dasIds: [$resto->id]);
    rvQuote(['500.00'], dasIds: [$supports->id]);
    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $resto);

    $result = DasRevenue::for($responsable, rvPeriod());
    expect(array_column($result['rows'], 'das_label'))->toBe(['Hôtellerie-restauration'])
        ->and($result['rows'][0]['quotes_cents'])->toBe(100000)
        ->and($result['totals']['total_cents'])->toBe(100000);
});

it('own scope counts only own quotes and opportunities, and lists every DAS', function () {
    $me = commercial();
    $colleague = commercial();
    $org = Organisation::query()->firstOrCreate(['name' => 'Le Bistrot']);

    $this->actingAs($colleague);
    rvQuote(['1000.00']); // colleague's
    rvWon('2000.00');     // colleague's

    $this->actingAs($me);
    rvQuote(['100.00']);  // mine
    rvWon('200.00');      // mine
    // A quote I created on the colleague's opportunity is mine too.
    $theirs = makeOpportunity($org, ['responsible_user_id' => $colleague->id, 'created_by' => $colleague->id]);
    DB::table('opportunities')->where('id', $theirs->id)->update(['created_by' => $colleague->id, 'responsible_user_id' => $colleague->id]);
    makeQuote($theirs->fresh())->forceFill(['status' => QuoteStatus::Accepted, 'accepted_on' => '2026-11-10'])->save();

    $result = DasRevenue::for($me, rvPeriod());
    expect($result['totals'])->toMatchArray(['quotes_cents' => 100000 + 10000, 'opportunities_cents' => 20000])
        ->and(count($result['rows']))->toBe(count(DasRevenue::for(direction(), rvPeriod())['rows']));
});

it('refuses a user without the revenue right', function () {
    DasRevenue::for(assistant(), rvPeriod());
})->throws(HttpException::class);

it('lists the active DAS at zero, in reference order, and a deactivated DAS only with revenue or a target', function () {
    $das = crmItem(ReferenceList::Das, 'Métiers supports');
    $other = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $active = ReferenceItem::options(ReferenceList::Das);
    $expected = $active->pluck('label')->all();
    expect(array_column(DasRevenue::for(direction(), rvPeriod())['rows'], 'das_label'))->toBe($expected);

    $das->forceFill(['is_active' => false])->save();
    expect(array_column(DasRevenue::for(direction(), rvPeriod())['rows'], 'das_label'))->not->toContain('Métiers supports');

    rvQuote(['100.00'], dasIds: [$das->id]);
    $labels = array_column(DasRevenue::for(direction(), rvPeriod())['rows'], 'das_label');
    expect($labels)->toContain('Métiers supports')->and($other->label)->toBeIn($labels);
});

it('a deactivated DAS with only a target on the period is listed', function () {
    $das = crmItem(ReferenceList::Das, 'Métiers supports');
    $das->forceFill(['is_active' => false])->save();
    $this->actingAs(direction());
    app(RevenueTargets::class)->set($das->id, rvPeriod(), 50000);

    expect(rvRows(direction())['Métiers supports']['target_cents'])->toBe(50000);
});

it('computes the rate with and without target, on the exact period type', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    rvQuote(['1000.00'], dasIds: [$resto->id]);
    rvQuote(['500.00'], dasIds: [$supports->id]);
    $this->actingAs(direction());
    $targets = app(RevenueTargets::class);
    $targets->set($resto->id, rvPeriod(), 300000); // 1000 / 3000 = 33,3 %
    $targets->set($supports->id, Period::of('quarter', '2026-T4'), 100000); // quarter only

    $month = DasRevenue::for(direction(), rvPeriod());
    $rows = collect($month['rows'])->keyBy('das_label');
    expect($rows['Hôtellerie-restauration']['target_cents'])->toBe(300000)
        ->and($rows['Hôtellerie-restauration']['rate'])->toBe(33.3)
        ->and($rows['Métiers supports']['target_cents'])->toBeNull()
        ->and($rows['Métiers supports']['rate'])->toBeNull()
        ->and($month['totals']['target_cents'])->toBe(300000);

    $quarter = collect(DasRevenue::for(direction(), Period::of('quarter', '2026-T4'))['rows'])->keyBy('das_label');
    expect($quarter['Métiers supports']['rate'])->toBe(50.0)
        ->and($quarter['Hôtellerie-restauration']['target_cents'])->toBeNull();
});

it('computes the total rate on the DAS that have a target only', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    rvQuote(['2000.00'], dasIds: [$resto->id]);
    rvQuote(['50000.00'], dasIds: [$supports->id]);
    $this->actingAs(direction());
    app(RevenueTargets::class)->set($resto->id, rvPeriod(), 1000000);

    $totals = DasRevenue::for(direction(), rvPeriod())['totals'];

    // 2 000 € of the 10 000 € target: the 50 000 € of the DAS without target do not count.
    expect($totals['total_cents'])->toBe(5200000)
        ->and($totals['target_cents'])->toBe(1000000)
        ->and($totals['targeted_total_cents'])->toBe(200000)
        ->and($totals['rate'])->toBe(20.0);
});

it('never uses floats: 0,10 € on three lines is exactly 30 cents', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    rvQuote(['0.10', '0.10', '0.10'], dasIds: [$resto->id, $resto->id, $resto->id]);
    rvWon('0.10');
    rvWon('0.20');

    $row = rvRows(direction())['Hôtellerie-restauration'];
    expect($row['quotes_cents'])->toBe(30)->and($row['opportunities_cents'])->toBe(30)->and($row['total_cents'])->toBe(60)
        ->and($row['quotes_cents'])->toBeInt();
});

it('details the sources of a line with the same scope', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $quote = rvQuote(['1000.00', '50.00'], '2026-11-10', [$resto->id, null]);
    $won = rvWon('300.00', '2026-11-05');
    rvWon('999.00', '2026-12-05'); // other period

    $sources = RevenueSources::for(direction(), rvPeriod(), $resto->id);
    expect($sources)->toHaveCount(2)
        ->and($sources[0])->toMatchArray(['kind' => 'opportunity', 'id' => $won->id, 'number_or_title' => 'Formation HACCP', 'account_label' => 'Le Bistrot', 'date' => '2026-11-05', 'amount_cents' => 30000])
        ->and($sources[1])->toMatchArray(['kind' => 'quote', 'id' => $quote->id, 'number_or_title' => $quote->number, 'date' => '2026-11-10', 'amount_cents' => 105000]);
    // Line 2 has no DAS: the opportunity's DAS (resto), so both lines belong to it.

    expect(RevenueSources::for(commercial(), rvPeriod(), $resto->id))->toBe([]);
    $responsable = userWithRole(Role::ResponsableDas);
    expect(RevenueSources::for($responsable, rvPeriod(), $resto->id))->toBe([]);
    followDas($responsable, $resto);
    expect(RevenueSources::for($responsable, rvPeriod(), $resto->id))->toHaveCount(2);
});
