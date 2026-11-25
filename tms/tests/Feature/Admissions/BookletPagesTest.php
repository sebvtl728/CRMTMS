<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Companies\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-15 10:00', 'Europe/Paris'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lists only the apprentices of the trainer', function () {
    $c = bookletApprentice();

    $this->actingAs(bookletTrainerUser('Cauret'))->get('/admissions/livret')->assertInertia(fn (Assert $page) => $page
        ->component('admissions/booklet/index')
        ->has('rows', 1)
        ->where('rows.0.id', $c->id)
        ->where('rows.0.apprentice', 'Léa Durand')
        ->where('rows.0.employer', 'Hôtel du Parc')
        ->where('rows.0.pending', 2));
    $this->actingAs(bookletTrainerUser('Autre'))->get('/admissions/livret')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    $this->actingAs(userWithRole(Role::Commercial))->get('/admissions/livret')->assertForbidden();
});

it('shows the follow-up of an apprentice without the candidature data', function () {
    $c = bookletApprentice();
    $c->forceFill(['rqth' => true, 'interview_report' => 'Entretien-confidentiel-7Q'])->save();

    $response = $this->actingAs(bookletTrainerUser('Cauret'))->get("/admissions/livret/{$c->id}?onglet=grille")->assertInertia(fn (Assert $page) => $page
        ->component('admissions/booklet/show')
        ->where('candidature.tutor', 'Paul Martin')
        ->where('tab', 'grille')
        ->has('months', 2)
        ->has('grid.types', 1)
        ->has('visits', 0)
        ->missing('months.0.pages.company.apprentice.drawing'));
    expect($response->getContent())->not->toContain('Entretien-confidentiel-7Q');
});

it('lets the trainer download the booklet of his apprentice only', function () {
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 livret', 200, ['Content-Type' => 'application/pdf'])]);
    $c = bookletApprentice();

    $this->actingAs(bookletTrainerUser('Cauret'))->get("/admissions/livret/{$c->id}/livret.pdf")->assertOk()->assertContent('%PDF-1.7 livret');
    $this->actingAs(bookletTrainerUser('Autre'))->get("/admissions/livret/{$c->id}/livret.pdf")->assertNotFound();
});
