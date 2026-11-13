<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Crm\Gdpr\Consent;
use App\Modules\Crm\Gdpr\ConsentChannel;
use App\Modules\Crm\Gdpr\LegalBasis;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;

beforeEach(function () {
    tenant();
});

it('sets the default legal basis from the type of person', function () {
    $individual = makePerson('Roux', ['is_individual' => true]);
    $contact = makePerson('Durand');

    expect($individual->refresh()->legal_basis)->toBe(LegalBasis::Consent)
        ->and($contact->refresh()->legal_basis)->toBe(LegalBasis::LegitimateInterest)
        ->and(LegalBasis::LegitimateInterest->label())->toBe('Intérêt légitime')
        ->and(array_map(fn (LegalBasis $b) => $b->value, LegalBasis::cases()))
        ->toBe(['interet_legitime', 'consentement', 'contrat', 'obligation_legale']);
});

it('creates an interlocuteur through an entreprise with legitimate interest', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/affiliations", [
        'mode' => 'new', 'last_name' => 'Petit', 'job_title' => 'Gérant',
    ])->assertSessionHasNoErrors();

    expect(Person::query()->where('last_name', 'Petit')->sole()->legal_basis)->toBe(LegalBasis::LegitimateInterest);
});

it('lets the user choose and change the legal basis', function () {
    $user = commercial();
    $this->actingAs($user)->post('/crm/people', ['last_name' => 'Roux', 'is_individual' => true, 'legal_basis' => 'contrat'])->assertSessionHasNoErrors();
    $person = Person::query()->sole();
    expect($person->legal_basis)->toBe(LegalBasis::Contract);

    $this->actingAs($user)->put("/crm/people/{$person->id}", ['last_name' => 'Roux', 'legal_basis' => 'obligation_legale'])->assertSessionHasNoErrors();
    expect($person->refresh()->legal_basis)->toBe(LegalBasis::LegalObligation);

    $this->actingAs($user)->put("/crm/people/{$person->id}", ['last_name' => 'Roux', 'legal_basis' => 'pirate'])->assertSessionHasErrors('legal_basis');
});

it('keeps the history of consents (appended, never changed)', function () {
    $person = makePerson('Roux');
    $user = commercial();

    $this->actingAs($user)->post("/crm/people/{$person->id}/consents", ['channel' => 'email', 'granted' => true, 'source' => 'Formulaire salon'])->assertSessionHasNoErrors();
    $this->travel(1)->minutes();
    $this->actingAs($user)->post("/crm/people/{$person->id}/consents", ['channel' => 'email', 'granted' => false, 'source' => 'Appel téléphonique'])->assertSessionHasNoErrors();

    $consents = Consent::query()->where('person_id', $person->id)->orderBy('recorded_at')->get();
    expect($consents)->toHaveCount(2)
        ->and($consents->pluck('granted')->all())->toBe([true, false])
        ->and($consents[0]->channel)->toBe(ConsentChannel::Email)
        ->and($consents[0]->source)->toBe('Formulaire salon')
        ->and($consents[0]->recorded_by)->toBe($user->id);

    expect(fn () => $consents[0]->update(['granted' => false]))->toThrow(LogicException::class);

    $this->actingAs($user)->post("/crm/people/{$person->id}/consents", ['channel' => 'fax', 'granted' => true])->assertSessionHasErrors('channel');

    // The fiche shows the history, newest first.
    $this->actingAs($user)->get("/crm/people/{$person->id}")->assertInertia(fn ($page) => $page
        ->where('gdpr.consents.0.granted', false)
        ->where('gdpr.consents.0.channel.label', 'E-mail')
        ->where('gdpr.consents.1.source', 'Formulaire salon'));
});

it('records the opposition to prospecting and shows a badge', function () {
    $person = makePerson('Roux', ['email' => 'roux@example.test']);
    $user = commercial();

    $this->actingAs($user)->post("/crm/people/{$person->id}/prospecting-opt-out")->assertRedirect();

    expect($person->refresh()->prospecting_opt_out_at)->not->toBeNull();
    $consent = Consent::query()->where('person_id', $person->id)->sole();
    expect($consent->channel)->toBe(ConsentChannel::Prospecting)->and($consent->granted)->toBeFalse();

    $this->actingAs($user)->get('/crm/people')->assertInertia(fn ($page) => $page->where('people.data.0.prospecting_opt_out', true));
    $this->actingAs($user)->getJson('/crm/people/search?q=roux')->assertJsonPath('people.0.prospecting_opt_out', true);

    // A new consent to prospecting lifts it.
    $this->actingAs($user)->post("/crm/people/{$person->id}/consents", ['channel' => 'prospecting', 'granted' => true, 'source' => 'Nouveau formulaire'])->assertSessionHasNoErrors();
    expect($person->refresh()->prospecting_opt_out_at)->toBeNull();
});

it('needs crm.update to record consents', function () {
    $person = makePerson('Roux');

    $this->actingAs(direction(twoFactor: true))->post("/crm/people/{$person->id}/consents", ['channel' => 'email', 'granted' => true])->assertForbidden();
    $this->actingAs(direction(twoFactor: true))->post("/crm/people/{$person->id}/prospecting-opt-out")->assertForbidden();
    expect(Consent::query()->count())->toBe(0);
});

it('journals a consent without its free text', function () {
    $person = makePerson('Roux');
    $this->actingAs(commercial())->post("/crm/people/{$person->id}/consents", ['channel' => 'sms', 'granted' => true, 'source' => 'Salon Ornithorynque'])->assertSessionHasNoErrors();

    $journal = AuditLog::query()->get()->map(fn ($l) => json_encode([$l->old_values, $l->new_values], JSON_UNESCAPED_UNICODE))->implode(' ');
    expect($journal)->not->toContain('Ornithorynque');
});

it('shows the opposition in the global search too', function () {
    makePerson('Roux', ['city' => 'Pau']);
    $person = Person::query()->sole();
    $person->forceFill(['prospecting_opt_out_at' => now()])->save();

    $this->actingAs(commercial())->getJson('/crm/search?q=roux')->assertJsonPath('people.0.subtitle', 'Pau · Opposé à la prospection');
});
