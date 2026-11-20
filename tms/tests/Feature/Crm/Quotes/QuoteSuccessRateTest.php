<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Quotes\QuoteSuccessRate;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * A quote sent at $sentAt (Paris), accepted on $acceptedOn when given.
 *
 * @param  array<string, mixed>  $opportunity
 */
function srQuote(string $sentAt, ?string $acceptedOn = null, array $opportunity = []): Quote
{
    $org = Organisation::query()->firstOrCreate(['name' => 'Le Bistrot']);
    $quote = makeQuote(makeOpportunity($org, $opportunity));
    $quote->forceFill([
        'status' => $acceptedOn === null ? QuoteStatus::Sent : QuoteStatus::Accepted,
        'sent_at' => CarbonImmutable::parse($sentAt, 'Europe/Paris'),
        'accepted_on' => $acceptedOn,
    ])->save();

    return $quote;
}

/** Four quotes sent in November (Paris), one accepted in December; one sent in October; a draft. */
function srNovember(): void
{
    srQuote('2026-11-01 00:15', '2026-12-02'); // still 31 October in UTC
    srQuote('2026-11-10 09:00');
    srQuote('2026-11-20 09:00');
    srQuote('2026-11-30 23:30'); // 22:30 UTC: still November
    srQuote('2026-10-31 23:30'); // 22:30 UTC: October in Paris
    makeQuote(makeOpportunity(Organisation::query()->firstOrCreate(['name' => 'Le Bistrot'])));
}

it('rate: 4 sent in november, 1 accepted in december gives 25 % for november', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 10:00', 'Europe/Paris'));
    srNovember();
    $user = commercial();

    $november = QuoteSuccessRate::for($user, CarbonImmutable::parse('2026-11-01', 'Europe/Paris'), CarbonImmutable::parse('2026-11-30', 'Europe/Paris'), []);
    expect($november)->toBe(['sent' => 4, 'accepted' => 1, 'rate' => 25.0]);

    $december = QuoteSuccessRate::for($user, CarbonImmutable::parse('2026-12-01', 'Europe/Paris'), CarbonImmutable::parse('2026-12-31', 'Europe/Paris'), []);
    expect($december)->toBe(['sent' => 0, 'accepted' => 0, 'rate' => null]);

    $quarter = QuoteSuccessRate::for($user, CarbonImmutable::parse('2026-10-01', 'Europe/Paris'), CarbonImmutable::parse('2026-12-31', 'Europe/Paris'), []);
    expect($quarter)->toBe(['sent' => 5, 'accepted' => 1, 'rate' => 20.0]);
});

it('a quote replaced by a new version leaves the rate: only the new version counts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'Europe/Paris'));
    $v1 = srQuote('2026-11-05 09:00');
    $v2 = srQuote('2026-11-12 09:00', '2026-11-20');
    // v1 refused as « Remplacé par » v2 (QuoteTransitions::newVersion).
    $v1->forceFill(['status' => QuoteStatus::Refused, 'refused_on' => '2026-11-12', 'refusal_reason' => "Remplacé par {$v2->number}", 'replaced_by_id' => $v2->id])->save();

    expect(QuoteSuccessRate::for(commercial(), CarbonImmutable::parse('2026-11-01', 'Europe/Paris'), CarbonImmutable::parse('2026-11-30', 'Europe/Paris'), []))
        ->toBe(['sent' => 1, 'accepted' => 1, 'rate' => 100.0]);
});

it('shows the rate above the list, by month, quarter or year (Paris)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:45', 'Europe/Paris')); // still November in Paris
    srNovember();
    $this->actingAs(commercial());

    $this->get('/crm/quotes')->assertInertia(fn (Assert $page) => $page
        ->where('success_rate.sent', 4)
        ->where('success_rate.accepted', 1)
        ->where('success_rate.rate', 25)
        ->where('success_rate.period', 'month')
        ->where('success_rate.label', 'Novembre 2026')
        ->where('success_rate.periods', [
            ['value' => 'month', 'label' => 'Mois'],
            ['value' => 'quarter', 'label' => 'Trimestre'],
            ['value' => 'year', 'label' => 'Année'],
        ]));

    $this->get('/crm/quotes?period=quarter')->assertInertia(fn (Assert $page) => $page
        ->where('success_rate.sent', 5)->where('success_rate.rate', 20)->where('success_rate.label', '4e trimestre 2026'));
    $this->get('/crm/quotes?period=year')->assertInertia(fn (Assert $page) => $page
        ->where('success_rate.sent', 5)->where('success_rate.label', 'Année 2026'));
    $this->get('/crm/quotes?period=bogus')->assertInertia(fn (Assert $page) => $page->where('success_rate.period', 'month'));

    // The list filters apply (not the status: the rate is about sent quotes).
    $this->get('/crm/quotes?status=accepted&q=inconnu')->assertInertia(fn (Assert $page) => $page
        ->where('success_rate.sent', 0)->where('success_rate.rate', null));
    $this->get('/crm/quotes?status=accepted')->assertInertia(fn (Assert $page) => $page->where('success_rate.sent', 4));
});

it('none sent gives no rate (« - »), on the list and on the dashboard', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 10:00', 'Europe/Paris'));
    srNovember();
    $this->actingAs(commercial());

    $this->get('/crm/quotes')->assertInertia(fn (Assert $page) => $page
        ->where('success_rate.sent', 0)->where('success_rate.accepted', 0)->where('success_rate.rate', null)
        ->where('success_rate.label', 'Décembre 2026'));
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('commercial.quotes', ['sent' => 0, 'accepted' => 0, 'rate' => null, 'month' => 'Décembre 2026']));

    $this->travelTo(CarbonImmutable::parse('2026-11-30 12:00', 'Europe/Paris'));
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('commercial.quotes', ['sent' => 4, 'accepted' => 1, 'rate' => 25, 'month' => 'Novembre 2026']));
});

it('Responsable DAS: the rate is limited to his DAS', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 12:00', 'Europe/Paris'));
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    srQuote('2026-11-10 09:00', '2026-11-12', ['das_id' => $mine->id]);
    srQuote('2026-11-11 09:00', null, ['das_id' => $mine->id]);
    srQuote('2026-11-12 09:00', '2026-11-13', ['das_id' => $other->id]);
    srQuote('2026-11-13 09:00', '2026-11-14', ['das_id' => $other->id]);
    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);

    $from = CarbonImmutable::parse('2026-11-01', 'Europe/Paris');
    $to = CarbonImmutable::parse('2026-11-30', 'Europe/Paris');
    expect(QuoteSuccessRate::for($responsable, $from, $to, []))->toBe(['sent' => 2, 'accepted' => 1, 'rate' => 50.0])
        ->and(QuoteSuccessRate::for(commercial(), $from, $to, []))->toBe(['sent' => 4, 'accepted' => 3, 'rate' => 75.0])
        ->and(QuoteSuccessRate::for(commercial(), $from, $to, ['das' => $other->id]))->toBe(['sent' => 2, 'accepted' => 2, 'rate' => 100.0]);

    $this->actingAs($responsable);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('commercial.quotes.sent', 2)->where('commercial.quotes.rate', 50));
});
