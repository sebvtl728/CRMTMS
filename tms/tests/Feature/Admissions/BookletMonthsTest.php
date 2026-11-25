<?php

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Booklet\BookletLink;
use App\Modules\Admissions\Booklet\BookletMonth;
use App\Modules\Admissions\Booklet\BookletMonths;
use App\Modules\Core\Companies\Company;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-15 10:00', 'Europe/Paris'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lists the months of the contract up to the current month of Paris', function () {
    $c = bookletApprentice(); // contract 04/01/2027 – 31/03/2027, séances in January and February

    $months = BookletMonths::months($c);
    expect(array_column($months, 'month'))->toBe(['2027-02', '2027-01'])
        ->and($months[1]['label'])->toBe('Janvier 2027')
        ->and($months[1]['places'])->toBe(['company', 'centre'])
        ->and($months[1]['pages']['company']['apprentice']['status'])->toBe('none')
        ->and($months[1]['pages']['centre']['tutor'])->toBeNull()
        ->and(array_column(BookletMonths::months($c, wholeContract: true), 'month'))->toBe(['2027-01', '2027-02', '2027-03'])
        ->and(BookletMonths::pending($c))->toBe(2);
});

it('saves centre observations then freezes them with the visa', function () {
    $c = bookletApprentice();
    $trainer = bookletTrainerUser('Cauret');
    $url = "/admissions/livret/{$c->id}/mois/2027-01/centre";

    $this->actingAs($trainer)->put("{$url}/observations", ['observations' => ['w1' => ' Bonne mise en place ', 'w2' => '']])->assertSessionHasNoErrors();
    $this->actingAs($trainer)->post("{$url}/visa")->assertRedirect();

    $page = BookletMonths::months($c)[1]['pages']['centre'];
    expect($page['observations'])->toBe(['w1' => 'Bonne mise en place', 'w2' => null, 'w3' => null, 'w45' => null])
        ->and($page['visa']['by'])->toBe($trainer->name)
        ->and($page['visa']['at'])->toBe('15/02/2027');
    $this->actingAs($trainer)->put("{$url}/observations", ['observations' => ['w1' => 'autre']])->assertStatus(409);

    $this->actingAs($trainer)->delete("{$url}/visa")->assertRedirect();
    expect(BookletMonths::months($c)[1]['pages']['centre']['visa'])->toBeNull();
});

it('refuses observations on the company page, a month out of the contract or to come', function () {
    $c = bookletApprentice();
    $trainer = bookletTrainerUser('Cauret');
    $this->actingAs($trainer)->put("/admissions/livret/{$c->id}/mois/2027-01/company/observations", ['observations' => ['w1' => 'x']])->assertSessionHasErrors('observations');
    $this->actingAs($trainer)->put("/admissions/livret/{$c->id}/mois/2026-12/centre/observations", ['observations' => ['w1' => 'x']])->assertStatus(409);
    $this->actingAs($trainer)->put("/admissions/livret/{$c->id}/mois/2027-03/centre/observations", ['observations' => ['w1' => 'x']])->assertStatus(409);
    $this->actingAs($trainer)->put("/admissions/livret/{$c->id}/mois/2027-01/centre/observations", ['observations' => ['w1' => str_repeat('a', 2001)]])->assertSessionHasErrors('observations.w1');
});

it('marks the apprentice part signed on paper on every page of the month and closes its open link', function () {
    $c = bookletApprentice();
    $link = BookletLink::query()->create(['candidature_id' => $c->id, 'month' => '2027-01-01', 'signer' => 'apprentice', 'person_id' => $c->person_id,
        'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDays(10)]);

    $this->actingAs(bookletTrainerUser('Cauret'))->post("/admissions/livret/{$c->id}/mois/2027-01/papier/apprentice")->assertRedirect();

    $january = BookletMonths::months($c)[1];
    expect($january['pages']['company']['apprentice']['status'])->toBe('paper')
        ->and($january['pages']['centre']['apprentice']['status'])->toBe('paper')
        ->and($january['pages']['company']['tutor']['status'])->toBe('none')
        ->and($link->fresh()->closed_at)->not->toBeNull()
        ->and(BookletMonths::pending($c))->toBe(1);
});

it('keeps rows of months dropped from a shortened contract out of the list without failing', function () {
    $c = bookletApprentice();
    BookletMonths::page($c, '2027-02', 'company');
    AlternanceContract::query()->where('candidature_id', $c->id)->update(['ends_on' => '2027-01-31']);

    expect(array_column(BookletMonths::months($c), 'month'))->toBe(['2027-01'])
        ->and(BookletMonth::query()->count())->toBe(1);
});
