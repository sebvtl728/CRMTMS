<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function lakeHotel(array $attributes = []): Organisation
{
    return Organisation::query()->create(['name' => 'Hôtel du Lac', ...$attributes]);
}

function decisionRole(): string
{
    return ReferenceItem::query()->where('list', ReferenceList::DecisionRole)->orderBy('position')->firstOrFail()->id;
}

it('creates an interlocuteur from an entreprise in a single entry', function () {
    $org = lakeHotel(['status' => 'client']);
    $user = commercial();

    $this->actingAs($user)->post("/crm/organisations/{$org->id}/affiliations", [
        'civility' => 'M.', 'first_name' => 'Paul', 'last_name' => 'Roux', 'job_title' => 'Directeur', 'decision_role_id' => decisionRole(),
        'work_email' => 'roux@hotel.fr', 'work_phone' => '04 72 00 11 22', 'is_referent' => true, 'started_on' => '2026-02-01',
    ])->assertRedirect(route('crm.organisations.show', $org));

    $person = Person::query()->sole();
    $affiliation = Affiliation::query()->sole();
    expect($person->last_name)->toBe('Roux')
        ->and($person->is_individual)->toBeFalse()
        ->and($person->status->value)->toBe('client')
        ->and($person->created_by)->toBe($user->id)
        ->and($affiliation->person_id)->toBe($person->id)
        ->and($affiliation->organisation_id)->toBe($org->id)
        ->and($affiliation->job_title)->toBe('Directeur')
        ->and($affiliation->work_phone)->toBe('+33472001122')
        ->and($affiliation->is_referent)->toBeTrue()
        ->and($affiliation->started_on?->toDateString())->toBe('2026-02-01')
        ->and($affiliation->ended_on)->toBeNull();
});

it('needs a last name or an existing person, and nothing else', function () {
    $org = lakeHotel();
    $this->actingAs(commercial())->postJson("/crm/organisations/{$org->id}/affiliations", ['job_title' => 'Seul'])->assertJsonValidationErrors('last_name');
    $this->postJson("/crm/organisations/{$org->id}/affiliations", ['last_name' => 'X', 'work_email' => 'nope'])->assertJsonValidationErrors('work_email');
    $this->postJson("/crm/organisations/{$org->id}/affiliations", ['last_name' => 'X', 'decision_role_id' => 'nope'])->assertJsonValidationErrors('decision_role_id');
    $this->postJson("/crm/organisations/{$org->id}/affiliations", ['last_name' => 'X', 'started_on' => '2026-05-01', 'ended_on' => '2026-04-01'])->assertJsonValidationErrors('ended_on');

    $this->post("/crm/organisations/{$org->id}/affiliations", ['last_name' => 'Minimal'])->assertRedirect();
    expect(Affiliation::count())->toBe(1);
});

it('attaches an existing person and refuses a second active affiliation to the same entreprise', function () {
    $org = lakeHotel();
    $person = makePerson('Particulière', ['is_individual' => true]);
    $this->actingAs(commercial());

    $this->post("/crm/organisations/{$org->id}/affiliations", ['person_id' => $person->id, 'job_title' => 'Gérante'])->assertRedirect();
    expect(Affiliation::count())->toBe(1)->and(Person::count())->toBe(1)->and($person->refresh()->is_individual)->toBeTrue();

    $this->postJson("/crm/organisations/{$org->id}/affiliations", ['person_id' => $person->id])
        ->assertJsonValidationErrors('person_id');
    expect(Affiliation::count())->toBe(1);
});

it('lets the database refuse two active affiliations of the same pair', function () {
    $org = lakeHotel();
    $person = makePerson('Unique');
    affiliate($person, $org);

    expect(fn () => DB::transaction(fn () => affiliate($person, $org)))->toThrow(UniqueConstraintViolationException::class);
    // Another entreprise is fine.
    affiliate($person, lakeHotel(['name' => 'Autre']));
    expect(Affiliation::count())->toBe(2);
});

it('allows a new affiliation once the previous one has ended', function () {
    $org = lakeHotel();
    $person = makePerson('Revenue');
    $first = affiliate($person, $org);
    $user = commercial();

    $this->actingAs($user)->put("/crm/affiliations/{$first->id}", ['ended_on' => '2026-03-31'])->assertRedirect(route('crm.organisations.show', $org));
    expect($first->refresh()->ended_on?->toDateString())->toBe('2026-03-31');

    $this->post("/crm/organisations/{$org->id}/affiliations", ['person_id' => $person->id, 'job_title' => 'De retour'])->assertRedirect();
    expect(Affiliation::count())->toBe(2)->and(Affiliation::query()->whereNull('ended_on')->sole()->job_title)->toBe('De retour');

    // Re-opening the ended one would make two active ones: refused, not a 500.
    $this->putJson("/crm/affiliations/{$first->id}", ['ended_on' => null])->assertJsonValidationErrors('ended_on');
});

it('updates an affiliation partially or completely', function () {
    $org = lakeHotel();
    $affiliation = affiliate(makePerson('Roux'), $org, ['job_title' => 'Ancien poste']);
    $this->actingAs(commercial());

    $this->put("/crm/affiliations/{$affiliation->id}", ['is_referent' => true])->assertRedirect();
    expect($affiliation->refresh()->is_referent)->toBeTrue()->and($affiliation->job_title)->toBe('Ancien poste');

    $this->put("/crm/affiliations/{$affiliation->id}", ['job_title' => 'Nouveau poste', 'work_phone' => '0612345678', 'work_email' => '', 'decision_role_id' => decisionRole()])->assertRedirect();
    expect($affiliation->refresh()->job_title)->toBe('Nouveau poste')->and($affiliation->work_phone)->toBe('+33612345678')->and($affiliation->decision_role_id)->toBe(decisionRole());
});

it('deletes an affiliation without deleting the person', function () {
    $org = lakeHotel();
    $person = makePerson('Garde');
    $affiliation = affiliate($person, $org);

    $this->actingAs(commercial())->delete("/crm/affiliations/{$affiliation->id}")->assertRedirect(route('crm.organisations.show', $org));

    expect(Affiliation::count())->toBe(0)->and(Affiliation::withTrashed()->count())->toBe(1)->and(Person::count())->toBe(1);
    $this->put("/crm/affiliations/{$affiliation->id}", ['job_title' => 'X'])->assertNotFound();
});

it('keeps the people when their entreprise is deleted and brings the affiliations back on restore', function () {
    $org = lakeHotel();
    $person = makePerson('Reste');
    $affiliation = affiliate($person, $org);
    $user = commercial();

    $this->actingAs($user)->delete("/crm/organisations/{$org->id}")->assertRedirect();

    expect(Organisation::count())->toBe(0)->and(Person::count())->toBe(1)->and(Affiliation::count())->toBe(0)
        ->and(Affiliation::withTrashed()->find($affiliation->id)?->deleted_at)->not->toBeNull();
    // The person is still listed, no longer an interlocuteur.
    $this->get('/crm/people?type=contact')->assertInertia(fn (Assert $page) => $page->has('people.data', 0));
    $this->get('/crm/people')->assertInertia(fn (Assert $page) => $page->has('people.data', 1));
    // No new affiliation on a deleted entreprise.
    $this->post("/crm/organisations/{$org->id}/affiliations", ['last_name' => 'Nouveau'])->assertNotFound();

    $this->post("/crm/organisations/{$org->id}/restore")->assertRedirect();
    expect(Affiliation::query()->find($affiliation->id))->not->toBeNull();
});

it('rejects an affiliation the user cannot make', function () {
    $org = lakeHotel();
    $person = makePerson('Fermée');
    $this->actingAs(userWithRole(Role::Direction, twoFactor: true))->post("/crm/organisations/{$org->id}/affiliations", ['person_id' => $person->id])->assertForbidden();

    // Creating a new person from the entreprise needs crm.fiches.update.
    expect(Affiliation::count())->toBe(0);
});

it('hides entreprises and affiliations a Responsable DAS may not see', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $visible = lakeHotel();
    $visible->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $hidden = lakeHotel(['name' => 'Cachée']);
    $affiliation = affiliate(makePerson('Cachée'), $hidden);
    $stranger = makePerson('Étrangère');
    $user = userWithRole(Role::ResponsableDas);
    followDas($user, $mine);
    // The default Responsable DAS is read-only: give him the right to edit to test the visibility.
    $user->givePermissionTo('crm.fiches.update');
    $this->actingAs($user);

    $this->post("/crm/organisations/{$hidden->id}/affiliations", ['last_name' => 'X'])->assertNotFound();
    $this->put("/crm/affiliations/{$affiliation->id}", ['job_title' => 'X'])->assertNotFound();
    $this->delete("/crm/affiliations/{$affiliation->id}")->assertNotFound();
    // He cannot attach a person he cannot see either.
    $this->postJson("/crm/organisations/{$visible->id}/affiliations", ['person_id' => $stranger->id])->assertJsonValidationErrors('person_id');
    $this->post("/crm/organisations/{$visible->id}/affiliations", ['last_name' => 'Nouveau'])->assertRedirect();
    expect(Affiliation::count())->toBe(2);
});

it('rejects an inactive decision role except the stored one', function () {
    $org = lakeHotel();
    $role = ReferenceItem::query()->findOrFail(decisionRole());
    $affiliation = affiliate(makePerson('Rôle'), $org, ['decision_role_id' => $role->id]);
    $role->update(['is_active' => false]);
    $this->actingAs(commercial());

    $this->put("/crm/affiliations/{$affiliation->id}", ['decision_role_id' => $role->id, 'job_title' => 'Ok'])->assertRedirect();
    $this->postJson("/crm/organisations/{$org->id}/affiliations", ['last_name' => 'Neuve', 'decision_role_id' => $role->id])->assertJsonValidationErrors('decision_role_id');
});

it('lists the interlocuteurs on the entreprise page, ended ones apart', function () {
    $org = lakeHotel();
    $referent = affiliate(makePerson('Roux', ['first_name' => 'Paul']), $org, ['job_title' => 'Directeur', 'is_referent' => true, 'work_email' => 'roux@hotel.fr', 'work_phone' => '0472001122', 'decision_role_id' => decisionRole()]);
    affiliate(makePerson('Martin', ['first_name' => 'Léa']), $org, ['job_title' => 'Cheffe']);
    affiliate(makePerson('Ancien'), $org, ['ended_on' => '2026-01-01']);
    $this->actingAs(commercial());

    $this->get("/crm/organisations/{$org->id}")->assertInertia(fn (Assert $page) => $page
        ->component('crm/organisations/show')
        ->has('affiliations', 2)
        ->where('affiliations.0.id', $referent->id)
        ->where('affiliations.0.person.name', 'Paul Roux')
        ->where('affiliations.0.job_title', 'Directeur')
        ->where('affiliations.0.work_phone', '+33472001122')
        ->where('affiliations.0.is_referent', true)
        ->where('affiliations.0.decision_role.label', fn ($l) => is_string($l))
        ->has('formerAffiliations', 1)
        ->has('decisionRoles')
        ->where('can.affiliate', true));
});

it('searches people to attach, leaving out those already attached', function () {
    $org = lakeHotel();
    affiliate(makePerson('Rouxdeja'), $org);
    makePerson('Rouxlibre', ['first_name' => 'Anne', 'email' => 'anne@example.org']);
    $other = lakeHotel(['name' => 'Autre hôtel']);
    affiliate(makePerson('Rouxailleurs'), $other, ['job_title' => 'Chef']);
    $this->actingAs(commercial());

    $found = $this->getJson("/crm/people/search?q=roux&organisation={$org->id}")->assertOk()->json('people');

    expect(collect($found)->pluck('last_name')->sort()->values()->all())->toBe(['Rouxailleurs', 'Rouxlibre'])
        ->and(collect($found)->firstWhere('last_name', 'Rouxlibre'))->toHaveKeys(['id', 'name', 'email', 'organisations'])
        ->and(collect($found)->firstWhere('last_name', 'Rouxailleurs')['organisations'])->toBe(['Autre hôtel']);
    $this->getJson('/crm/people/search?q=')->assertOk()->assertJson(['people' => []]);
    $this->getJson('/crm/people/search?q=r')->assertOk()->assertJson(['people' => []]);
});

it('stores the mobile of a new interlocuteur on the person, normalised', function () {
    $org = lakeHotel();
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/affiliations", [
        'first_name' => 'Léa', 'last_name' => 'Martin', 'mobile' => '06 12 34 56 78', 'work_email' => 'lea@hotel.fr',
    ])->assertRedirect();

    expect(Person::query()->sole()->mobile)->toBe('+33612345678')
        ->and(Affiliation::query()->sole()->getAttributes())->not->toHaveKey('mobile');
});

it('refuses an invalid mobile in French', function () {
    $org = lakeHotel();
    $this->actingAs(commercial())->postJson("/crm/organisations/{$org->id}/affiliations", [
        'last_name' => 'Martin', 'mobile' => str_repeat('1', 40),
    ])->assertJsonValidationErrors(['mobile' => 'Ce champ est trop long.']);
    expect(Person::count())->toBe(0);
});

it('does not change the mobile of an existing person picked in the dialog', function () {
    $org = lakeHotel();
    $person = makePerson('Existante', ['mobile' => '0698765432']);
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/affiliations", ['person_id' => $person->id, 'mobile' => '0611111111'])->assertRedirect();

    expect($person->fresh()->mobile)->toBe('+33698765432');
});

it('lists the mobile of visible people only', function () {
    $org = lakeHotel();
    $seen = makePerson('Visible', ['mobile' => '0612345678']);
    $hidden = makePerson('Cachee', ['mobile' => '0687654321']);
    affiliate($seen, $org);
    affiliate($hidden, $org);
    $user = userWithPermissions([Permissions::FICHES_VIEW]);
    DB::table('role_permission_scopes')->insert(['tenant_id' => tenant()->id, 'role_id' => $user->roles()->firstOrFail()->id, 'permission' => Permissions::FICHES_VIEW, 'scope' => 'own']);
    Scopes::forget();
    DB::table('organisations')->where('id', $org->id)->update(['created_by' => $user->id]);
    DB::table('people')->where('id', $seen->id)->update(['created_by' => $user->id]);

    $this->actingAs($user)->get("/crm/organisations/{$org->id}")->assertInertia(fn (Assert $page) => $page
        ->where('affiliations', fn ($rows) => collect($rows)->map(fn ($r) => $r['person']['name'].'='.($r['mobile'] ?? '-'))->sort()->values()->all() === ['Cachee=-', 'Visible=+33612345678']));

    $this->actingAs(commercial())->get("/crm/organisations/{$org->id}")->assertInertia(fn (Assert $page) => $page
        ->where('affiliations', fn ($rows) => collect($rows)->pluck('mobile')->sort()->values()->all() === ['+33612345678', '+33687654321']));
});
