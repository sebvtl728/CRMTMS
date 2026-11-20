<?php

use App\Modules\Catalogue\Training;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicators;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityTraining;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Revenue\Period;
use App\Modules\Crm\Revenue\RevenueTargets;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'Europe/Paris')); // month: November 2026
});

function l2TrainingFor(string $title): Training
{
    return Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '21.00']);
}

/** The whole IndicatorValue (month tab) as an array. */
function dashMonth(string $key, User $user, array $input = []): array
{
    return Indicators::get($key)->value(DashboardFilters::from($input, CarbonImmutable::now(), 'month'), $user)->toArray();
}

/** An opportunity of $account won on $on for $amount (no quote). */
function dashWon(Organisation|Person $account, string $amount, string $on, array $attributes = []): Opportunity
{
    $opportunity = makeOpportunity($account, ['amount' => $amount, ...$attributes]);
    $opportunity->forceFill(['stage_id' => pipelineStage('Gagné')->id, 'won_on' => $on, 'probability' => 100])->save();

    return $opportunity;
}

it('rates the conversion of the opportunities created in the period', function () {
    $user = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    dashWon($hotel, '1000', '2026-11-12');
    makeOpportunity($hotel);
    makeOpportunity($hotel);
    makeOpportunity($hotel)->forceFill(['stage_id' => pipelineStage('Perdu')->id, 'lost_on' => '2026-11-15'])->save();

    expect(dashMonth('crm.conversion', $user)['value'])->toBe(25.0)
        ->and(dashMonth('crm.conversion', $user, ['preset' => 'last_month'])['value'])->toBeNull();
});

it('sums the forecast of the open opportunities closing in the period', function () {
    $user = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    makeOpportunity($hotel, ['amount' => '1200', 'expected_close_on' => '2026-11-28']);
    makeOpportunity($hotel, ['amount' => '800.50', 'expected_close_on' => '2026-11-02']);
    makeOpportunity($hotel, ['amount' => '5000', 'expected_close_on' => '2026-12-02']);
    dashWon($hotel, '9000', '2026-11-12', ['expected_close_on' => '2026-11-20']);

    expect(dashMonth('crm.forecast', $user)['value'])->toBe(200050);
});

it('measures the sales cycle of the opportunities won in the period, mean and median in days', function () {
    $user = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    foreach ([['2026-11-01', '2026-11-11'], ['2026-10-20', '2026-11-09'], ['2026-09-30', '2026-11-29']] as [$created, $won]) {
        $this->travelTo(CarbonImmutable::parse($created.' 10:00', 'Europe/Paris'));
        $o = makeOpportunity($hotel, ['amount' => '100']);
        $o->forceFill(['stage_id' => pipelineStage('Gagné')->id, 'won_on' => $won])->save();
    }
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'Europe/Paris'));

    $cycle = dashMonth('crm.sales_cycle', $user);
    expect($cycle['value'])->toBe(30.0)->and($cycle['note'])->toBe('Médiane : 20 j');
});

it('counts the trainings sold, by training: accepted quote lines, else the trainings of the opportunity', function () {
    $user = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $haccp = l2TrainingFor('HACCP 3 jours');
    $service = l2TrainingFor('Service en salle');
    $quoted = makeOpportunity($hotel);
    $quote = makeQuote($quoted, null, [
        ['training_id' => $haccp->id, 'label' => 'HACCP', 'description' => null, 'quantity' => '1.00', 'unit' => 'stagiaire', 'unit_price' => '500.00', 'discount_percent' => '0.00', 'vat_rate' => '0.00'],
        ['training_id' => $service->id, 'label' => 'Service', 'description' => null, 'quantity' => '1.00', 'unit' => 'stagiaire', 'unit_price' => '500.00', 'discount_percent' => '0.00', 'vat_rate' => '0.00'],
    ]);
    $quote->forceFill(['status' => QuoteStatus::Accepted, 'accepted_on' => '2026-11-10'])->save();
    $won = dashWon($hotel, '700', '2026-11-12');
    OpportunityTraining::query()->create(['opportunity_id' => $won->id, 'training_id' => $haccp->id, 'quantity' => 1, 'position' => 0]);

    $sold = dashMonth('crm.trainings_sold', $user);
    expect($sold['value'])->toBe(3)
        ->and($sold['breakdown'])->toBe([['label' => 'HACCP 3 jours', 'value' => 2], ['label' => 'Service en salle', 'value' => 1]]);
});

it('splits the signed revenue between entreprises and particuliers, and by lead origin', function () {
    $user = commercial();
    $fair = crmItem(ReferenceList::ContactOrigin, 'Salon');
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc', 'origin_id' => $fair->id]);
    $ana = makePerson('Roux', ['first_name' => 'Ana', 'is_individual' => true]);
    dashWon($hotel, '1000', '2026-11-12');
    dashWon($ana, '250.50', '2026-11-13');
    dashWon($ana, '99', '2026-10-13'); // last month

    $split = dashMonth('crm.target_split', $user);
    expect($split['value'])->toBe(125050)
        ->and($split['breakdown'])->toBe([['label' => 'Entreprises', 'value' => 100000], ['label' => 'Particuliers', 'value' => 25050]]);

    $origins = dashMonth('crm.lead_origins', $user);
    expect($origins['breakdown'])->toBe([['label' => 'Salon', 'value' => 100000], ['label' => 'Non renseignée', 'value' => 25050]]);
});

it('counts the lost opportunities of the period by loss reason', function () {
    $user = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $price = crmItem(ReferenceList::LossReason, 'Prix');
    foreach (['2026-11-03', '2026-11-04', '2026-10-30'] as $on) {
        makeOpportunity($hotel, ['amount' => '100'])->forceFill(['stage_id' => pipelineStage('Perdu')->id, 'lost_on' => $on, 'loss_reason_id' => $price->id])->save();
    }

    $losses = dashMonth('crm.loss_reasons', $user);
    expect($losses['value'])->toBe(2)->and($losses['breakdown'])->toBe([['label' => 'Prix', 'value' => 2]]);
});

it('shows the revenue by DAS and its target, only with crm.revenue.view', function () {
    $user = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    // His revenue only (« Ses fiches » for crm.revenue.view): the sale is his.
    dashWon($hotel, '1000', '2026-11-12', ['responsible_user_id' => $user->id]);
    dashWon($hotel, '3000', '2026-11-12');
    $this->actingAs(admin());
    app(RevenueTargets::class)->set(crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, Period::of('month', '2026-11'), 500000);

    $revenue = dashMonth('crm.das_revenue', $user);
    expect($revenue['value'])->toBe(100000)->and($revenue['target_cents'])->toBe(500000)
        ->and(collect($revenue['breakdown'])->firstWhere('label', 'Hôtellerie-restauration')['value'])->toBe(100000)
        ->and(Indicators::get('crm.das_revenue')->permission())->toBe(Permissions::REVENUE_VIEW);
});
