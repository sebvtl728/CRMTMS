<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Revenue\Period;
use App\Modules\Crm\Revenue\RevenueTarget;
use App\Modules\Crm\Revenue\RevenueTargets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00', 'Europe/Paris'));
});

function revPage(User $user, string $query = ''): array
{
    test()->actingAs($user);

    return test()->get('/crm/revenue'.$query)->assertOk()->original->getData()['page']['props'];
}

function revQuote(string $price, string $dasId): void
{
    $org = Organisation::query()->firstOrCreate(['name' => 'Le Bistrot']);
    $quote = makeQuote(makeOpportunity($org), null, [quoteLine(['unit_price' => $price, 'quantity' => '1'])]);
    DB::table('quote_lines')->where('quote_id', $quote->id)->update(['das_id' => $dasId]);
    $quote->forceFill(['status' => QuoteStatus::Accepted, 'accepted_on' => '2026-11-10'])->save();
}

it('shows all DAS to the direction, on the current Paris month by default', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    revQuote('1000.00', $resto->id);

    $props = revPage(direction(true));

    expect($props['period'])->toMatchArray(['type' => 'month', 'key' => '2026-11', 'label' => 'novembre 2026', 'previous_key' => '2026-10', 'next_key' => '2026-12'])
        ->and($props['own'])->toBeFalse()
        ->and($props['can']['manage'])->toBeTrue()
        ->and(collect($props['rows'])->pluck('das_label'))->toContain('Hôtellerie-restauration')
        ->and(collect($props['rows'])->count())->toBeGreaterThan(1)
        ->and(collect($props['rows'])->firstWhere('das_label', 'Hôtellerie-restauration')['quotes_cents'])->toBe(100000)
        ->and($props['totals']['total_cents'])->toBe(100000);
});

it('reads the period type and key from the query', function () {
    expect(revPage(direction(true), '?period=quarter&key=2026-T3')['period'])->toMatchArray(['type' => 'quarter', 'key' => '2026-T3', 'previous_key' => '2026-T2', 'next_key' => '2026-T4'])
        ->and(revPage(direction(true), '?period=year')['period'])->toMatchArray(['type' => 'year', 'key' => '2026', 'next_key' => '2027']);
});

it('limits a Responsable DAS to the DAS he follows', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    revQuote('500.00', $supports->id);
    $user = userWithRole(Role::ResponsableDas, true);
    followDas($user, $resto);

    $props = revPage($user);

    expect(collect($props['rows'])->pluck('das_label')->all())->toBe(['Hôtellerie-restauration'])
        ->and($props['totals']['total_cents'])->toBe(0);
});

it('hides targets from a user limited to his own fiches', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $this->actingAs(direction(true));
    app(RevenueTargets::class)->set($resto->id, Period::of('month', '2026-11'), 100000);

    $props = revPage(commercial(true));

    expect($props['own'])->toBeTrue()
        ->and($props['can']['manage'])->toBeFalse()
        ->and(collect($props['rows'])->every(fn ($r) => $r['target_cents'] === null && $r['rate'] === null))->toBeTrue()
        ->and($props['totals']['target_cents'])->toBeNull();
});

it('refuses an invalid period with a French message', function () {
    $this->actingAs(direction(true));

    $this->get('/crm/revenue?period=month&key=2026-13')->assertStatus(422)->assertSee('La période demandée est invalide.');
    $this->get('/crm/revenue?period=week')->assertStatus(422);
});

it('is forbidden without crm.revenue.view', function () {
    $this->actingAs(assistant(true));

    $this->get('/crm/revenue')->assertForbidden();
});

it('lists the detail of a DAS as JSON, with links', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    revQuote('1250.50', $resto->id);
    $this->actingAs(direction(true));

    $response = $this->getJson("/crm/revenue/{$resto->id}/sources?period=month&key=2026-11")->assertOk();

    expect($response->json('sources'))->toHaveCount(1)
        ->and($response->json('sources.0'))->toMatchArray(['kind' => 'quote', 'amount_cents' => 125050, 'date' => '2026-11-10'])
        ->and($response->json('sources.0.url'))->toContain('/crm/quotes/');
});

it('answers 404 for the detail of a DAS the Responsable DAS does not follow, or an unknown one', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    $user = userWithRole(Role::ResponsableDas, true);
    followDas($user, $resto);
    $this->actingAs($user);

    $this->getJson("/crm/revenue/{$supports->id}/sources")->assertNotFound();
    $this->getJson("/crm/revenue/{$resto->id}/sources")->assertOk();
    $this->getJson('/crm/revenue/'.Str::uuid().'/sources')->assertNotFound();
    $this->getJson('/crm/revenue/not-a-uuid/sources')->assertNotFound();
});

it('sets and clears a target from a French amount', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $this->actingAs(direction(true));

    $this->put('/crm/revenue/targets', ['das_id' => $resto->id, 'period' => 'month', 'key' => '2026-11', 'amount' => '10 000,50'])->assertRedirect();
    expect(RevenueTarget::query()->sole()->amount)->toBe('10000.50');

    $this->put('/crm/revenue/targets', ['das_id' => $resto->id, 'period' => 'month', 'key' => '2026-11', 'amount' => '12 500 €'])->assertRedirect();
    expect(RevenueTarget::query()->sole()->amount)->toBe('12500.00');

    $this->put('/crm/revenue/targets', ['das_id' => $resto->id, 'period' => 'month', 'key' => '2026-11', 'amount' => ''])->assertRedirect();
    expect(RevenueTarget::query()->count())->toBe(0);
});

it('refuses a bad amount or period on a target', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $this->actingAs(direction(true));

    $this->put('/crm/revenue/targets', ['das_id' => $resto->id, 'period' => 'month', 'key' => '2026-11', 'amount' => '10,555'])->assertSessionHasErrors('amount');
    $this->put('/crm/revenue/targets', ['das_id' => $resto->id, 'period' => 'month', 'key' => '2026-11', 'amount' => 'abc'])->assertSessionHasErrors('amount');
    $this->put('/crm/revenue/targets', ['das_id' => $resto->id, 'period' => 'month', 'key' => '2026-99', 'amount' => '10'])->assertSessionHasErrors('period');
    expect(RevenueTarget::query()->count())->toBe(0);
});

it('forbids setting a target without crm.revenue.manage', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $this->actingAs(commercial(true));

    $this->put('/crm/revenue/targets', ['das_id' => $resto->id, 'period' => 'month', 'key' => '2026-11', 'amount' => '100'])->assertForbidden();
    expect(RevenueTarget::query()->count())->toBe(0);
});

it('computes the next period', function () {
    expect(Period::of('month', '2026-12')->next()->key())->toBe('2027-01')
        ->and(Period::of('quarter', '2026-T4')->next()->key())->toBe('2027-T1')
        ->and(Period::of('quarter', '2026-T1')->next()->key())->toBe('2026-T2')
        ->and(Period::of('year', '2026')->next()->key())->toBe('2027');
});

it('shows the signed revenue of the month on the home page', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    revQuote('800.00', $resto->id);

    $this->actingAs(direction(true));
    $revenue = test()->get('/dashboard')->assertOk()->original->getData()['page']['props']['commercial']['revenue'];
    expect($revenue)->toMatchArray(['total_cents' => 80000, 'own' => false, 'month' => 'novembre 2026']);

    $this->actingAs(assistant(true));
    expect(test()->get('/dashboard')->original->getData()['page']['props']['commercial']['revenue'])->toBeNull();
});

it('compares the home card with the target on the DAS that have one', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    revQuote('2000.00', $resto->id);
    revQuote('50000.00', $supports->id);
    $this->actingAs(direction(true));
    app(RevenueTargets::class)->set($resto->id, Period::of('month', '2026-11'), 1000000);

    $revenue = test()->get('/dashboard')->original->getData()['page']['props']['commercial']['revenue'];
    expect($revenue)->toMatchArray(['total_cents' => 5200000, 'target_cents' => 1000000, 'targeted_total_cents' => 200000, 'rate' => 20.0]);

    $totals = revPage(direction(true))['totals'];
    expect($totals['rate'])->toBe(20.0)->and($totals['targeted_total_cents'])->toBe(200000);
});

it('refuses an oversized target with a French message, not a 500', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $this->actingAs(direction(true));

    $this->put('/crm/revenue/targets', ['das_id' => $resto->id, 'period' => 'month', 'key' => '2026-11', 'amount' => '99999999999999999999,99'])
        ->assertSessionHasErrors('amount');
    expect(RevenueTarget::query()->count())->toBe(0);
});

it('shows no target on the home card of a user limited to his own fiches', function () {
    $resto = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $this->actingAs(direction(true));
    app(RevenueTargets::class)->set($resto->id, Period::of('month', '2026-11'), 100000);

    $this->actingAs(commercial(true));
    $revenue = test()->get('/dashboard')->original->getData()['page']['props']['commercial']['revenue'];
    expect($revenue)->toMatchArray(['own' => true, 'target_cents' => null, 'total_cents' => 0]);
});
