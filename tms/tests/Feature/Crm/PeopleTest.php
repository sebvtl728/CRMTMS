<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/** @return list<string> last names of the list, in order */
function peopleNames(mixed $test, string $query = ''): array
{
    return collect($test->get('/crm/people'.$query)->original->getData()['page']['props']['people']['data'])->pluck('last_name')->all();
}

it('creates a particulier with only a last name', function () {
    $user = commercial();

    $this->actingAs($user)->post('/crm/people', ['last_name' => 'Dupont', 'is_individual' => true])->assertRedirect();

    $person = Person::query()->sole();
    expect($person->last_name)->toBe('Dupont')
        ->and($person->is_individual)->toBeTrue()
        ->and($person->status->value)->toBe('prospect')
        ->and($person->first_name)->toBeNull()
        ->and($person->created_by)->toBe($user->id);
    $this->get("/crm/people/{$person->id}")->assertOk();
});

it('requires a last name and validates the optional fields', function () {
    $this->actingAs(commercial())->postJson('/crm/people', ['first_name' => 'Sans nom'])->assertJsonValidationErrors('last_name');
    $this->postJson('/crm/people', ['last_name' => 'X', 'email' => 'pas-un-mail'])->assertJsonValidationErrors('email');
    $this->postJson('/crm/people', ['last_name' => 'X', 'civility' => 'Dr'])->assertJsonValidationErrors('civility');
    $this->postJson('/crm/people', ['last_name' => 'X', 'birth_date' => '2999-01-01'])->assertJsonValidationErrors('birth_date');
    $this->postJson('/crm/people', ['last_name' => 'X', 'birth_date' => '03/02/1980'])->assertJsonValidationErrors('birth_date');
    $this->postJson('/crm/people', ['last_name' => 'X', 'status' => 'autre'])->assertJsonValidationErrors('status');
    expect(Person::count())->toBe(0);
});

it('creates a person with references, DAS and normalised phones', function () {
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $user = commercial();

    $this->actingAs($user)->post('/crm/people', [
        'civility' => 'Mme', 'first_name' => 'Léa', 'last_name' => 'Martin', 'email' => 'lea@example.org',
        'phone' => '04 72 00 11 22', 'mobile' => '06.12.34.56.78', 'birth_date' => '1990-05-17', 'project' => 'Reconversion',
        'is_individual' => true,
        'professional_situation_id' => crmItem(ReferenceList::ProfessionalSituation, 'Demandeur d\'emploi')->id,
        'funding_mode_id' => crmItem(ReferenceList::FundingMode, 'CPF')->id,
        'origin_id' => crmItem(ReferenceList::ContactOrigin, 'Salon')->id,
        'responsible_user_id' => $user->id,
        'das_ids' => [$das->id],
    ])->assertRedirect();

    $person = Person::query()->with('das')->sole();
    expect($person->phone)->toBe('+33472001122')
        ->and($person->mobile)->toBe('+33612345678')
        ->and($person->birth_date?->toDateString())->toBe('1990-05-17')
        ->and($person->civility)->toBe('Mme')
        ->and($person->das->pluck('id')->all())->toBe([$das->id])
        ->and(DB::table('person_das')->where('person_id', $person->id)->value('tenant_id'))->toBe(tenant()->id);
    expect(AuditLog::query()->where('event', 'crm.person_das_changed')->count())->toBe(1);
});

it('keeps an unrecognised phone as typed', function () {
    $this->actingAs(commercial())->post('/crm/people', ['last_name' => 'X', 'phone' => 'poste 12'])->assertRedirect();

    expect(Person::query()->sole()->phone)->toBe('poste 12');
});

it('updates a person and clears optional fields', function () {
    $person = makePerson('Ancien', ['first_name' => 'Jean', 'email' => 'jean@example.org']);

    $this->actingAs(commercial())->put("/crm/people/{$person->id}", ['last_name' => 'Nouveau', 'first_name' => 'Jean', 'email' => '', 'city' => 'Lyon'])
        ->assertRedirect(route('crm.people.show', $person));

    $person->refresh();
    expect($person->last_name)->toBe('Nouveau')->and($person->email)->toBeNull()->and($person->city)->toBe('Lyon');
});

it('searches by name, e-mail and phone without caring for accents or spacing', function () {
    makePerson('Éléonore', ['first_name' => 'Zoé', 'email' => 'zoe@exemple.fr', 'phone' => '0612345678']);
    makePerson('Autre', ['mobile' => '+41221234567']);
    $this->actingAs(commercial());

    expect(peopleNames($this, '?q=eleonore'))->toBe(['Éléonore'])
        ->and(peopleNames($this, '?q=zoe+eleo'))->toBe(['Éléonore'])
        ->and(peopleNames($this, '?q=exemple.fr'))->toBe(['Éléonore'])
        ->and(peopleNames($this, '?q='.urlencode('06 12 34 56')))->toBe(['Éléonore'])
        ->and(peopleNames($this, '?q='.urlencode('+33 6 12')))->toBe(['Éléonore'])
        ->and(peopleNames($this, '?q='.urlencode('+41 22 123')))->toBe(['Autre'])
        ->and(peopleNames($this, '?q=nobody'))->toBe([]);
});

it('also finds a person through the work e-mail or phone of an affiliation', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    affiliate(makePerson('Roux'), $org, ['work_email' => 'roux@hoteldulac.fr', 'work_phone' => '0472001122']);
    makePerson('Autre');
    $this->actingAs(commercial());

    expect(peopleNames($this, '?q=hoteldulac'))->toBe(['Roux'])
        ->and(peopleNames($this, '?q='.urlencode('04 72 00')))->toBe(['Roux']);
});

it('filters by type, status, situation, funding, origin, responsible and my fiches', function () {
    $user = commercial();
    $other = userWithRole(Role::Commercial);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $situation = crmItem(ReferenceList::ProfessionalSituation, 'Salarié');
    $funding = crmItem(ReferenceList::FundingMode, 'CPF');
    $origin = crmItem(ReferenceList::ContactOrigin, 'Salon');

    makePerson('Particulier', ['is_individual' => true, 'status' => 'client', 'professional_situation_id' => $situation->id, 'funding_mode_id' => $funding->id, 'responsible_user_id' => $user->id]);
    affiliate(makePerson('Interlocuteur', ['origin_id' => $origin->id, 'responsible_user_id' => $other->id]), $org);
    $ended = makePerson('Ancien interlocuteur');
    affiliate($ended, $org, ['ended_on' => '2026-01-01']);
    $this->actingAs($user);

    expect(peopleNames($this, '?type=individual'))->toBe(['Particulier'])
        ->and(peopleNames($this, '?type=contact'))->toBe(['Interlocuteur'])
        ->and(peopleNames($this, '?status=client'))->toBe(['Particulier'])
        ->and(peopleNames($this, '?situation='.$situation->id))->toBe(['Particulier'])
        ->and(peopleNames($this, '?funding='.$funding->id))->toBe(['Particulier'])
        ->and(peopleNames($this, '?origin='.$origin->id))->toBe(['Interlocuteur'])
        ->and(peopleNames($this, '?responsible='.$other->id))->toBe(['Interlocuteur'])
        ->and(peopleNames($this, '?mine=1'))->toBe(['Particulier'])
        ->and(peopleNames($this))->toBe(['Ancien interlocuteur', 'Interlocuteur', 'Particulier']);
});

it('sorts by name and last contact, ignores bogus filters', function () {
    makePerson('Bernard', ['first_name' => 'Anne', 'last_contact_at' => '2026-03-01']);
    makePerson('Adam', ['first_name' => 'Zoé']);
    makePerson('Adam', ['first_name' => 'Alice', 'last_contact_at' => '2026-05-01']);
    $this->actingAs(commercial());

    expect(collect($this->get('/crm/people')->original->getData()['page']['props']['people']['data'])->map(fn ($p) => $p['first_name'])->all())->toBe(['Alice', 'Zoé', 'Anne'])
        ->and(peopleNames($this, '?sort=last_contact_at&dir=desc'))->toBe(['Adam', 'Bernard', 'Adam'])
        ->and(peopleNames($this, '?status[]=client&type[]=x&sort[]=y&q[]=z'))->toHaveCount(3);
});

it('lists the trash and restores a person with the affiliations deleted together', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Corbeille');
    $affiliation = affiliate($person, $org);
    $user = commercial();

    $this->actingAs($user)->delete("/crm/people/{$person->id}")->assertRedirect(route('crm.people.index'));
    expect(Person::count())->toBe(0)->and(Affiliation::count())->toBe(0)->and(Organisation::count())->toBe(1);

    $this->get('/crm/people?trashed=1')->assertInertia(fn (Assert $page) => $page->has('people.data', 1));
    $this->get('/crm/people')->assertInertia(fn (Assert $page) => $page->has('people.data', 0));
    $this->put("/crm/people/{$person->id}", ['last_name' => 'Y'])->assertNotFound();
    $this->get("/crm/people/{$person->id}")->assertOk();

    $this->post("/crm/people/{$person->id}/restore")->assertRedirect(route('crm.people.show', $person));
    expect(Person::count())->toBe(1)->and(Affiliation::query()->find($affiliation->id))->not->toBeNull();
});

it('does not bring back an affiliation that was removed on its own before the person was deleted', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Ancienne');
    $removed = affiliate($person, $org);
    $removed->delete();
    $this->travel(1)->minute();
    $person->delete();

    $person->restore();

    expect(Affiliation::count())->toBe(0)->and(Affiliation::withTrashed()->find($removed->id))->not->toBeNull();
});

it('renders the fiche, the create form and the edit form with options', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Dupont', ['first_name' => 'Marie', 'is_individual' => true]);
    affiliate($person, $org, ['job_title' => 'Directrice', 'is_referent' => true]);
    $this->actingAs(commercial());

    $this->get("/crm/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
        ->component('crm/people/show')
        ->where('person.last_name', 'Dupont')
        ->where('person.is_individual', true)
        ->has('person.affiliations', 1)
        ->where('person.affiliations.0.organisation.name', 'Hôtel du Lac')
        ->where('person.affiliations.0.job_title', 'Directrice')
        ->where('can.update', true));

    $this->get('/crm/people/create')->assertInertia(fn (Assert $page) => $page
        ->component('crm/people/form')->where('person', null)
        ->has('options.professional_situation')->has('options.funding_mode')->has('options.origin')->has('options.das')->has('options.responsible')->has('options.status'));

    $this->get("/crm/people/{$person->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('crm/people/form')->where('person.id', $person->id)->where('person.first_name', 'Marie'));
});

it('forbids deleting to the assistant, creating and updating to the direction, and sends guests to login', function () {
    $person = makePerson('Protégée');

    $this->get('/crm/people')->assertRedirect('/login');
    $this->actingAs(assistant())->delete("/crm/people/{$person->id}")->assertForbidden();
    expect(Person::count())->toBe(1);

    $this->actingAs(userWithRole(Role::Direction, twoFactor: true))->post('/crm/people', ['last_name' => 'X'])->assertForbidden();
    $this->put("/crm/people/{$person->id}", ['last_name' => 'Y'])->assertForbidden();
    $this->get("/crm/people/{$person->id}")->assertOk();
});

it('answers 404 to a malformed id and to a person of another organisme', function () {
    $this->actingAs(commercial())->get('/crm/people/not-a-uuid')->assertNotFound();

    $other = tenantOnHost('autre.test');
    $foreign = app(TenantContext::class)->run($other, fn () => makePerson('Étrangère'));

    $this->get("/crm/people/{$foreign->id}")->assertNotFound();
});

it('limits a Responsable DAS to the people he may see', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $org = Organisation::query()->create(['name' => 'Visible SA']);
    $org->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $contact = makePerson('Contact');
    affiliate($contact, $org);
    $hidden = makePerson('Caché');
    $user = userWithRole(Role::ResponsableDas);
    followDas($user, $mine);
    $this->actingAs($user);

    expect(peopleNames($this))->toBe(['Contact']);
    $this->get("/crm/people/{$contact->id}")->assertOk();
    $this->get("/crm/people/{$hidden->id}")->assertNotFound();
    $this->get("/crm/people/{$hidden->id}/edit")->assertNotFound();
    $this->delete("/crm/people/{$hidden->id}")->assertNotFound();
});

it('rejects inactive reference items but keeps the one already stored', function () {
    $situation = crmItem(ReferenceList::ProfessionalSituation, 'Salarié');
    $funding = crmItem(ReferenceList::FundingMode, 'CPF');
    $person = makePerson('Ref', ['professional_situation_id' => $situation->id]);
    $situation->update(['is_active' => false]);
    $funding->update(['is_active' => false]);
    $this->actingAs(commercial());

    // The stored one stays valid on update...
    $this->put("/crm/people/{$person->id}", ['last_name' => 'Ref', 'professional_situation_id' => $situation->id])->assertRedirect();
    // ...a new assignment of an inactive item is refused (create and update).
    $this->putJson("/crm/people/{$person->id}", ['last_name' => 'Ref', 'funding_mode_id' => $funding->id])->assertJsonValidationErrors('funding_mode_id');
    $this->postJson('/crm/people', ['last_name' => 'Neuve', 'professional_situation_id' => $situation->id])->assertJsonValidationErrors('professional_situation_id');

    // The form still lists the stored inactive value, labelled.
    $this->get("/crm/people/{$person->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('options.professional_situation', fn ($items) => collect($items)->contains(fn ($i) => $i['value'] === $situation->id && str_ends_with($i['label'], '(inactif)')))
        ->where('options.funding_mode', fn ($items) => ! collect($items)->contains(fn ($i) => $i['value'] === $funding->id)));
});

it('rejects a reference of another list or another organisme', function () {
    $sector = crmItem(ReferenceList::Sector, 'Commerce');
    $this->actingAs(commercial())->postJson('/crm/people', ['last_name' => 'X', 'professional_situation_id' => $sector->id])->assertJsonValidationErrors('professional_situation_id');
    $this->postJson('/crm/people', ['last_name' => 'X', 'origin_id' => 'pas-un-id'])->assertJsonValidationErrors('origin_id');
});

it('rejects inactive reference items on entreprises too, except the stored one', function () {
    $sector = crmItem(ReferenceList::Sector, 'Commerce');
    $size = crmItem(ReferenceList::CompanySize, ReferenceItem::query()->where('list', ReferenceList::CompanySize)->value('label'));
    $org = Organisation::query()->create(['name' => 'Ancienne', 'sector_id' => $sector->id]);
    $sector->update(['is_active' => false]);
    $size->update(['is_active' => false]);
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $org->das()->sync([$das->id => ['tenant_id' => tenant()->id]]);
    $das->update(['is_active' => false]);
    $this->actingAs(commercial());

    $this->put("/crm/organisations/{$org->id}", ['name' => 'Ancienne', 'sector_id' => $sector->id, 'das_ids' => [$das->id]])->assertRedirect();
    $this->putJson("/crm/organisations/{$org->id}", ['name' => 'Ancienne', 'company_size_id' => $size->id])->assertJsonValidationErrors('company_size_id');
    $this->postJson('/crm/organisations', ['name' => 'Neuve', 'sector_id' => $sector->id])->assertJsonValidationErrors('sector_id');
    $this->postJson('/crm/organisations', ['name' => 'Neuve', 'das_ids' => [$das->id]])->assertJsonValidationErrors('das_ids.0');
});

it('protects reference items used by a person or an affiliation', function () {
    $situation = crmItem(ReferenceList::ProfessionalSituation, 'Salarié');
    $role = crmItem(ReferenceList::DecisionRole, ReferenceItem::query()->where('list', ReferenceList::DecisionRole)->value('label'));
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $person = makePerson('Utilisatrice', ['professional_situation_id' => $situation->id]);
    $person->das()->sync([$das->id => ['tenant_id' => tenant()->id]]);
    affiliate($person, Organisation::query()->create(['name' => 'O']), ['decision_role_id' => $role->id]);

    expect(ReferenceUsage::isInUse($situation))->toBeTrue()
        ->and(ReferenceUsage::isInUse($role))->toBeTrue()
        ->and(ReferenceUsage::isInUse($das))->toBeTrue()
        ->and(ReferenceUsage::isInUse(crmItem(ReferenceList::FundingMode, 'CPF')))->toBeFalse();
});

it('shows Personnes in the navigation data of a commercial', function () {
    $this->actingAs(commercial())->get('/crm/people')->assertInertia(fn (Assert $page) => $page
        ->component('crm/people/index')->where('auth.permissions', fn ($p) => collect($p)->contains('crm.fiches.view')));
});
