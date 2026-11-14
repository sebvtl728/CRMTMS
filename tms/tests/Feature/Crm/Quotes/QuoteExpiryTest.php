<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Timeline\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
});

function quoteIn(QuoteStatus $status): Quote
{
    $quote = makeQuote(makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot'])));
    $quote->forceFill(['status' => $status, 'sent_at' => $status === QuoteStatus::Draft ? null : now()])->save();

    return $quote;
}

it('expiry uses europe/paris end of day', function () {
    $sent = quoteIn(QuoteStatus::Sent);       // valid until 2026-12-14
    $reminded = quoteIn(QuoteStatus::Reminded);
    $draft = quoteIn(QuoteStatus::Draft);
    $accepted = quoteIn(QuoteStatus::Accepted);

    // 14/12 at 23:30 in Paris (22:30 UTC): still valid that whole day.
    $this->travelTo(CarbonImmutable::parse('2026-12-14 23:30', 'Europe/Paris'));
    $this->artisan('tms:crm:expire-quotes')->assertSuccessful();
    expect($sent->refresh()->status)->toBe(QuoteStatus::Sent);

    // 15/12 at 00:05 in Paris, still the 14th in UTC: expired.
    $this->travelTo(CarbonImmutable::parse('2026-12-15 00:05', 'Europe/Paris'));
    expect(now('UTC')->toDateString())->toBe('2026-12-14');
    $this->artisan('tms:crm:expire-quotes')->expectsOutput('Devis expirés : 2.')->assertSuccessful();

    expect($sent->refresh()->status)->toBe(QuoteStatus::Expired)
        ->and($reminded->refresh()->status)->toBe(QuoteStatus::Expired)
        ->and($draft->refresh()->status)->toBe(QuoteStatus::Draft)
        ->and($accepted->refresh()->status)->toBe(QuoteStatus::Accepted);

    $this->actingAs(commercial());
    expect(Timeline::for($sent->organisation)->where('type', 'quote')->pluck('title')->first())->toBe('Devis DEV-2026-0001 expiré');
});

it('expires the quotes of every organisme', function () {
    $here = quoteIn(QuoteStatus::Sent);
    $other = Tenant::factory()->create();
    $there = app(TenantContext::class)->run($other, function () {
        CapFormationReferenceSeeder::run(Tenant::query()->findOrFail(app(TenantContext::class)->id()));

        return quoteIn(QuoteStatus::Sent);
    });
    app(TenantContext::class)->clear();

    $this->travelTo(CarbonImmutable::parse('2026-12-15 00:05', 'Europe/Paris'));
    $this->artisan('tms:crm:expire-quotes')->assertSuccessful();

    inTenant();
    expect($here->refresh()->status)->toBe(QuoteStatus::Expired);
    app(TenantContext::class)->run($other, fn () => expect($there->refresh()->status)->toBe(QuoteStatus::Expired));
});

it('is scheduled daily at 00:05 paris time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, 'tms:crm:expire-quotes'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('5 0 * * *')
        ->and($event->timezone)->toBe('Europe/Paris');
});
