<?php

use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Permissions;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

it('searches the entreprises and the people of the CRM for the candidature forms', function () {
    Organisation::query()->create(['name' => 'Hôtel du Parc']);
    adPerson();

    $this->actingAs(commercial())->getJson('/admissions/candidatures/lookup/organisations?q=parc')->assertOk()
        ->assertJsonPath('entreprises.0.label', 'Hôtel du Parc')->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/admissions/candidatures/lookup/people?q=durand')->assertOk()->assertJsonPath('personnes.0.last_name', 'Durand');
    $this->getJson('/admissions/candidatures/lookup/people?q=d')->assertJsonPath('personnes', []);

    $this->actingAs(adUser([Permissions::CANDIDATURES_VIEW => null]))->getJson('/admissions/candidatures/lookup/people?q=durand')->assertForbidden();
});

it('creates the person of a new candidate, warning about a duplicate', function () {
    adPerson('Durand', ['email' => 'lea.durand@example.test']);
    $this->actingAs(commercial())->postJson('/admissions/candidatures/people', ['last_name' => 'Durant', 'first_name' => 'Léa', 'email' => 'lea.durand@example.test'])
        ->assertStatus(409)->assertJsonPath('duplicates.0.last_name', 'Durand');

    $id = $this->postJson('/admissions/candidatures/people', ['last_name' => 'Petit', 'first_name' => 'Tom', 'email' => 'tom@example.test'])
        ->assertCreated()->json('id');
    expect(Person::query()->find($id)?->is_individual)->toBeTrue();

    $this->postJson('/admissions/candidatures/people', ['last_name' => ''])->assertUnprocessable();
});

it('sends the history of the candidature to its page', function () {
    $c = adCandidature();
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::PositioningSent, admin());

    $this->actingAs(commercial())->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('history.0.title', "Candidature {$c->number} : Test envoyé")
        ->where('history.1.title', "Candidature {$c->number} ouverte"));
});
