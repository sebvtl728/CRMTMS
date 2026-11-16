<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleMatrix;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Public\FicheBlocks;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Sessions\Gdpr\FicheReferences;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\Trainers;
use App\Modules\Sessions\Trainers\TrainerUnavailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
});

function trainersManager(): User
{
    return userWithPermissions([Permissions::TRAINERS_MANAGE]);
}

function makeTrainer(?Person $person = null, array $attributes = []): Trainer
{
    return Trainer::query()->create(['person_id' => ($person ?? makePerson('Martin', ['first_name' => 'Paul', 'email' => 'paul.martin@example.test']))->id, 'status' => 'employee', ...$attributes]);
}

// ---------------------------------------------------------------- trainers

it('makes a TMS user trainer, the person of the CRM created behind the scenes', function () {
    $account = userIn(tenant(), ['name' => 'Léa Durand', 'email' => 'lea.durand@example.test']);
    $manager = trainersManager();

    $this->actingAs($manager)->post('/trainers', ['user_id' => $account->id, 'status' => 'freelance'])->assertRedirect()->assertSessionHasNoErrors();
    $trainer = Trainer::query()->sole();
    $person = Person::query()->findOrFail($trainer->person_id);
    expect($trainer->user_id)->toBe($account->id)->and($trainer->status)->toBe('freelance')->and($trainer->active)->toBeTrue()
        ->and([$person->first_name, $person->last_name, $person->email])->toBe(['Léa', 'Durand', 'lea.durand@example.test']);

    // The same account twice: refused.
    $this->actingAs($manager)->post('/trainers', ['user_id' => $account->id, 'status' => 'employee'])
        ->assertSessionHasErrors(['user_id' => 'Ce compte est déjà lié à un autre formateur.']);
    expect(Trainer::query()->count())->toBe(1);
});

it('reuses the one person of the CRM having the e-mail of the account', function () {
    $person = makePerson('Martin', ['first_name' => 'Paul', 'email' => 'Paul.Martin@example.test']);
    $account = userIn(tenant(), ['name' => 'Paul Martin', 'email' => 'paul.martin@example.test']);

    $this->actingAs(trainersManager())->post('/trainers', ['user_id' => $account->id, 'status' => 'employee'])->assertRedirect();

    expect(Trainer::query()->sole()->person_id)->toBe($person->id)
        ->and(Person::query()->where('email', 'ilike', '%martin%')->count())->toBe(1);
});

it('creates a new person when several people of the CRM have the e-mail', function () {
    makePerson('Martin', ['email' => 'paul@example.test']);
    makePerson('Martin bis', ['email' => 'paul@example.test']);
    $account = userIn(tenant(), ['name' => 'Paul', 'email' => 'paul@example.test']);

    $this->actingAs(trainersManager())->post('/trainers', ['user_id' => $account->id, 'status' => 'employee'])->assertRedirect();

    expect(Person::query()->find(Trainer::query()->sole()->person_id)?->last_name)->toBe('Paul')
        ->and(Person::query()->where('email', 'paul@example.test')->count())->toBe(3);
});

it('records a trainer without account from his name and e-mail; a later invitation links him', function () {
    $manager = trainersManager();

    $this->actingAs($manager)->post('/trainers', ['person' => ['last_name' => 'Petit', 'first_name' => 'Marc', 'email' => 'Marc.Petit@example.test'], 'status' => 'freelance'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $trainer = Trainer::query()->sole();
    $person = Person::query()->findOrFail($trainer->person_id);
    expect($trainer->user_id)->toBeNull()->and($trainer->status)->toBe('freelance')
        ->and([$person->first_name, $person->last_name, $person->email])->toBe(['Marc', 'Petit', 'marc.petit@example.test']);

    // The same person again: refused.
    $this->actingAs($manager)->post('/trainers', ['person' => ['last_name' => 'Petit', 'first_name' => 'Marc', 'email' => 'marc.petit@example.test'], 'status' => 'employee'])
        ->assertSessionHasErrors(['person.email' => 'Un formateur a déjà cet e-mail.']);

    $invitation = Invitation::issue('marc.petit@example.test', 'Marc Petit', RoleModel::forSystem(Role::Formateur));
    $this->post("/activation/{$invitation->plainToken}", ['password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide'])->assertRedirect('/login');

    expect(Trainer::query()->count())->toBe(1)
        ->and($trainer->refresh()->user_id)->toBe(User::query()->where('email', 'marc.petit@example.test')->sole()->id);
});

it('validates a trainer in French', function () {
    $this->actingAs(trainersManager())->post('/trainers', ['status' => 'intérimaire'])
        ->assertSessionHasErrors(['status' => 'Choisissez « Salarié » ou « Indépendant ».', 'user_id' => 'Choisissez un utilisateur ou saisissez un formateur sans compte.']);
    $this->actingAs(trainersManager())->post('/trainers', ['user_id' => '0190a0a0-0000-7000-8000-000000000000', 'status' => 'employee'])
        ->assertSessionHasErrors(['user_id' => "Ce compte n'existe pas."]);
    $this->actingAs(trainersManager())->post('/trainers', ['person' => ['last_name' => '', 'first_name' => str_repeat('a', 101), 'email' => 'pas-un-email'], 'status' => 'employee'])
        ->assertSessionHasErrors([
            'person.last_name' => 'Indiquez le nom du formateur.',
            'person.first_name' => 'Le prénom ne doit pas dépasser 100 caractères.',
            'person.email' => 'Indiquez une adresse e-mail valide.',
        ]);
    expect(Trainer::query()->count())->toBe(0);
});

it('offers the active users of the organisme, Formateur role first, without those already trainers', function () {
    $manager = trainersManager();
    $zoe = userIn(tenant(), ['name' => 'Zoé Formatrice']);
    $zoe->assignPrimaryRole(Role::Formateur);
    Trainer::query()->where('user_id', $zoe->id)->delete();
    Trainer::withTrashed()->where('user_id', $zoe->id)->update(['user_id' => null]);
    $anne = userIn(tenant(), ['name' => 'Anne Commerciale']);
    $gone = userIn(tenant(), ['name' => 'Ancien']);
    $gone->deactivate();
    $linked = userIn(tenant(), ['name' => 'Déjà lié']);
    makeTrainer(null, ['user_id' => $linked->id]);

    $this->actingAs($manager)->get('/trainers')->assertInertia(fn (Assert $page) => $page
        ->where('userOptions', function ($options) use ($zoe, $anne, $gone, $linked, $manager): bool {
            $ids = collect($options)->pluck('value')->all();

            return $ids[0] === $zoe->id && $options[0]['trainer_role'] === true
                && in_array($anne->id, $ids, true) && in_array($manager->id, $ids, true)
                && ! in_array($gone->id, $ids, true) && ! in_array($linked->id, $ids, true);
        }));
    $this->actingAs($manager)->get('/trainers/people?q=mar')->assertNotFound();
});

it('lists, shows and changes the trainers', function () {
    $trainer = makeTrainer();
    makeTrainer(makePerson('Albert', ['first_name' => 'Jean']), ['status' => 'freelance', 'active' => false]);
    $user = trainersManager();

    $this->actingAs($user)->get('/trainers')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sessions/trainers/index')
        ->has('trainers', 2)
        ->where('trainers.0.label', 'Jean Albert')
        ->where('trainers.0.status_label', 'Indépendant')
        ->where('trainers.0.active', false)
        ->where('trainers.1.email', 'paul.martin@example.test'));

    $this->actingAs($user)->get("/trainers/{$trainer->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sessions/trainers/show')
        ->where('trainer.id', $trainer->id)
        ->where('trainer.status', 'employee'));

    $this->actingAs($user)->put("/trainers/{$trainer->id}", ['status' => 'freelance', 'active' => false])->assertRedirect()->assertSessionHasNoErrors();
    expect($trainer->refresh()->status)->toBe('freelance')->and($trainer->active)->toBeFalse();
});

it('refuses the trainers to whoever lacks sessions.trainers.manage', function () {
    $trainer = makeTrainer();
    $user = userWithPermissions([Permissions::SESSIONS_UPDATE, Permissions::SEANCES_VIEW]);

    $this->actingAs($user)->get('/trainers')->assertForbidden();
    $this->actingAs($user)->get("/trainers/{$trainer->id}")->assertForbidden();
    $this->actingAs($user)->post('/trainers', ['person' => ['last_name' => 'X'], 'status' => 'employee'])->assertForbidden();
    $this->actingAs($user)->post("/trainers/{$trainer->id}/unavailabilities", ['starts_at' => '2027-01-04T09:00', 'ends_at' => '2027-01-04T12:00'])->assertForbidden();
});

it('deletes an unused trainer, never one in use', function () {
    $user = userIn(tenant());
    $free = makeTrainer(null, ['user_id' => $user->id]);
    $used = makeTrainer(makePerson('Occupé'));
    Trainers::registerUsage('test.seances', fn (string $id): bool => $id === $used->id);

    $this->actingAs(trainersManager())->delete("/trainers/{$used->id}")->assertStatus(409)
        ->assertSee('Ce formateur a des séances : désactivez-le plutôt que de le supprimer.');
    $this->actingAs(trainersManager())->delete("/trainers/{$free->id}")->assertRedirect('/trainers');

    expect(Trainer::query()->pluck('id')->all())->toBe([$used->id])
        ->and(Trainer::withTrashed()->find($free->id)?->user_id)->toBeNull();
});

it('restores a deleted trainer when the person is declared again', function () {
    $person = makePerson('Revenant', ['email' => 'revenant@example.test']);
    $old = makeTrainer($person, ['status' => 'freelance']);
    $old->delete();

    $this->actingAs(trainersManager())->post('/trainers', ['person' => ['last_name' => 'Revenant', 'first_name' => '', 'email' => 'revenant@example.test'], 'status' => 'employee'])->assertRedirect();

    expect(Trainer::query()->sole()->id)->toBe($old->id)->and($old->refresh()->status)->toBe('employee');
});

// ---------------------------------------------------------------- unavailabilities

it('records the unavailabilities of a trainer, in Paris time', function () {
    $trainer = makeTrainer();
    $user = trainersManager();

    $this->actingAs($user)->post("/trainers/{$trainer->id}/unavailabilities", ['starts_at' => '2027-03-29T09:00', 'ends_at' => '2027-03-29T17:30', 'reason' => 'Congés'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $u = TrainerUnavailability::query()->sole();
    expect($u->starts_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-29 07:00')
        ->and($u->ends_at->setTimezone('Europe/Paris')->format('H:i'))->toBe('17:30')
        ->and($u->reason)->toBe('Congés');

    $this->actingAs($user)->post("/trainers/{$trainer->id}/unavailabilities", ['starts_at' => '2027-03-29T09:00', 'ends_at' => '2027-03-29T09:00', 'reason' => str_repeat('a', 201)])
        ->assertSessionHasErrors(['ends_at' => 'La fin doit être après le début.', 'reason' => 'Le motif ne doit pas dépasser 200 caractères.']);

    $this->actingAs($user)->get("/trainers/{$trainer->id}")->assertInertia(fn (Assert $page) => $page
        ->has('unavailabilities', 1)->where('unavailabilities.0.label', 'Du 29/03/2027 09:00 au 29/03/2027 17:30'));

    $this->actingAs($user)->delete("/trainers/{$trainer->id}/unavailabilities/{$u->id}")->assertRedirect();
    expect(TrainerUnavailability::query()->count())->toBe(0);
});

it('never reaches the unavailability of another trainer through a trainer', function () {
    $a = makeTrainer();
    $b = makeTrainer(makePerson('Autre'));
    $u = TrainerUnavailability::query()->create(['trainer_id' => $b->id, 'starts_at' => '2027-01-04 09:00', 'ends_at' => '2027-01-04 12:00']);

    $this->actingAs(trainersManager())->delete("/trainers/{$a->id}/unavailabilities/{$u->id}")->assertNotFound();
    expect(TrainerUnavailability::query()->count())->toBe(1);
});

it('tells whether a trainer is available over a period', function () {
    $trainer = makeTrainer();
    TrainerUnavailability::query()->create(['trainer_id' => $trainer->id, 'starts_at' => CarbonImmutable::parse('2027-01-04 09:00', 'Europe/Paris'), 'ends_at' => CarbonImmutable::parse('2027-01-04 12:00', 'Europe/Paris')]);
    $at = fn (string $t) => CarbonImmutable::parse($t, 'Europe/Paris');

    expect(Trainers::available($trainer->id, $at('2027-01-04 12:00'), $at('2027-01-04 17:00')))->toBeTrue()
        ->and(Trainers::available($trainer->id, $at('2027-01-04 08:00'), $at('2027-01-04 09:00')))->toBeTrue()
        ->and(Trainers::available($trainer->id, $at('2027-01-04 11:00'), $at('2027-01-04 14:00')))->toBeFalse()
        ->and(Trainers::available($trainer->id, $at('2027-01-04 08:00'), $at('2027-01-04 18:00')))->toBeFalse()
        ->and(Trainers::available(makeTrainer(makePerson('Libre'))->id, $at('2027-01-04 08:00'), $at('2027-01-04 18:00')))->toBeTrue();
});

// ---------------------------------------------------------------- accounts

it('links a trainer to a user of the organisme by hand, one trainer per user', function () {
    $trainer = makeTrainer();
    $other = makeTrainer(makePerson('Second'));
    $account = userIn(tenant(), ['email' => 'compte@example.test']);
    $manager = trainersManager();

    $this->actingAs($manager)->put("/trainers/{$trainer->id}/user", ['user_id' => $account->id])->assertRedirect()->assertSessionHasNoErrors();
    expect($trainer->refresh()->user_id)->toBe($account->id)
        ->and(Trainers::forUser($account)?->id)->toBe($trainer->id);

    $this->actingAs($manager)->put("/trainers/{$other->id}/user", ['user_id' => $account->id])
        ->assertSessionHasErrors(['user_id' => 'Ce compte est déjà lié à un autre formateur.']);

    $this->actingAs($manager)->put("/trainers/{$trainer->id}/user", ['user_id' => null])->assertRedirect();
    expect($trainer->refresh()->user_id)->toBeNull()->and(Trainers::forUser($account))->toBeNull();
});

it('links the account to the trainer on invitation acceptance when exactly one trainer has the e-mail', function () {
    $trainer = makeTrainer(makePerson('Solo', ['email' => 'Solo@Example.test']));
    $invitation = Invitation::issue('solo@example.test', 'Solo', RoleModel::forSystem(Role::Commercial));

    $this->post("/activation/{$invitation->plainToken}", ['password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide'])
        ->assertRedirect('/login');

    expect($trainer->refresh()->user_id)->toBe(User::query()->where('email', 'solo@example.test')->sole()->id)
        ->and(Trainer::query()->count())->toBe(1);
});

it('creates the trainer of a user invited as Formateur, linked to him', function () {
    $invitation = Invitation::issue('nouveau@example.test', 'Jean Nouveau', RoleModel::forSystem(Role::Formateur));

    $this->post("/activation/{$invitation->plainToken}", ['password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide'])
        ->assertRedirect('/login');

    $user = User::query()->where('email', 'nouveau@example.test')->sole();
    $trainer = Trainer::query()->sole();
    expect($trainer->user_id)->toBe($user->id)->and($trainer->status)->toBe('employee')
        ->and(Person::query()->find($trainer->person_id)?->last_name)->toBe('Nouveau');
});

it('creates the trainer of a user whose role becomes Formateur, once', function () {
    $user = userIn(tenant(), ['name' => 'Claire Change', 'email' => 'claire@example.test']);
    $user->assignPrimaryRole(Role::Commercial);
    expect(Trainer::query()->count())->toBe(0);

    $user->assignPrimaryRole(Role::Formateur);
    expect(Trainer::query()->sole()->user_id)->toBe($user->id);

    $user->assignPrimaryRole(Role::Commercial);
    $user->assignPrimaryRole(Role::Formateur);
    expect(Trainer::query()->count())->toBe(1);
});

it('links nothing when several trainers have the e-mail', function () {
    $a = makeTrainer(makePerson('Double', ['email' => 'double@example.test']));
    $b = makeTrainer(makePerson('Double bis', ['email' => 'double@example.test']));
    $invitation = Invitation::issue('double@example.test', 'Double', RoleModel::forSystem(Role::Formateur));

    $this->post("/activation/{$invitation->plainToken}", ['password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide'])->assertRedirect('/login');

    expect($a->refresh()->user_id)->toBeNull()->and($b->refresh()->user_id)->toBeNull()
        ->and(Trainer::query()->count())->toBe(2);
});

it('offers to invite a trainer without account, the invitation page prefilled', function () {
    $trainer = makeTrainer();
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->get("/trainers/{$trainer->id}")->assertInertia(fn (Assert $page) => $page
        ->where('invite_url', '/admin/users/invite?'.http_build_query(['email' => 'paul.martin@example.test', 'name' => 'Paul Martin', 'role' => 'formateur'])));
    $this->actingAs(trainersManager())->get("/trainers/{$trainer->id}")->assertInertia(fn (Assert $page) => $page->where('invite_url', null));

    $this->actingAs($admin)->get('/admin/users/invite?email=paul.martin@example.test&name=Paul%20Martin&role=formateur')
        ->assertInertia(fn (Assert $page) => $page->where('defaults', ['email' => 'paul.martin@example.test', 'name' => 'Paul Martin', 'role' => 'formateur']));
});

// ---------------------------------------------------------------- « Mon planning » (the trainer himself)

it('lets a linked trainer record and remove his own unavailabilities, never another one', function () {
    // The Formateur role gave him his trainer record.
    $account = userWithRole(Role::Formateur);
    $mine = Trainers::forUser($account) ?? throw new RuntimeException('Formateur sans fiche.');
    $other = makeTrainer(makePerson('Autre'));
    $foreign = TrainerUnavailability::query()->create(['trainer_id' => $other->id, 'starts_at' => '2027-01-04 09:00', 'ends_at' => '2027-01-04 12:00']);

    $this->actingAs($account)->get('/mon-planning')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sessions/my-planning')->where('trainer.id', $mine->id));
    $this->actingAs($account)->post('/mon-planning/unavailabilities', ['starts_at' => '2027-01-05T08:00', 'ends_at' => '2027-01-05T18:00', 'reason' => ''])
        ->assertRedirect()->assertSessionHasNoErrors();
    $own = TrainerUnavailability::query()->where('trainer_id', $mine->id)->sole();

    $this->actingAs($account)->delete("/mon-planning/unavailabilities/{$foreign->id}")->assertNotFound();
    $this->actingAs($account)->delete("/mon-planning/unavailabilities/{$own->id}")->assertRedirect();
    expect(TrainerUnavailability::query()->pluck('id')->all())->toBe([$foreign->id]);
});

it('refuses « Mon planning » unavailabilities to a user linked to no trainer', function () {
    $account = userWithRole(Role::Formateur);
    // Unlinked by a manager, and two trainers share his e-mail: nothing can be linked automatically.
    Trainers::link(Trainers::forUser($account) ?? throw new RuntimeException('Formateur sans fiche.'), null);
    makeTrainer(makePerson('Homonyme', ['email' => $account->email]));

    $this->actingAs($account)->get('/mon-planning')->assertOk()->assertInertia(fn (Assert $page) => $page->where('trainer', null));
    $this->actingAs($account)->post('/mon-planning/unavailabilities', ['starts_at' => '2027-01-05T08:00', 'ends_at' => '2027-01-05T18:00'])->assertNotFound();
    $this->actingAs(commercial())->get('/mon-planning')->assertForbidden();
});

it('links the trainer again when a trainer-scoped user opens « Mon planning »', function () {
    $account = userWithRole(Role::Formateur);
    $trainer = Trainers::forUser($account) ?? throw new RuntimeException('Formateur sans fiche.');
    Trainers::link($trainer, null);

    $this->actingAs($account)->get('/mon-planning')->assertInertia(fn (Assert $page) => $page->where('trainer.id', $trainer->id));
    expect($trainer->refresh()->user_id)->toBe($account->id);
});

// ---------------------------------------------------------------- scope changes, safer person reuse

it('creates the trainers of a custom role switched to « Ses séances »', function () {
    $account = userWithPermissions([Permissions::SEANCES_VIEW]);
    expect(Trainers::forUser($account))->toBeNull();

    app(RoleMatrix::class)->setScope($account->roles()->firstOrFail(), Permissions::SEANCES_VIEW, AccessScope::Trainer);

    expect(Trainers::forUser($account)?->user_id)->toBe($account->id);
});

it('creates the trainer again when the Formateur role gets its séances right back', function () {
    $account = userWithRole(Role::Formateur);
    $trainer = Trainers::forUser($account) ?? throw new RuntimeException('Formateur sans fiche.');
    Trainers::delete($trainer);
    $role = RoleModel::forSystem(Role::Formateur);

    app(RoleMatrix::class)->set($role, Permissions::SEANCES_VIEW, false);
    expect(Trainers::forUser($account))->toBeNull();
    app(RoleMatrix::class)->set($role, Permissions::SEANCES_VIEW, true);

    // Same person: the trashed trainer comes back, linked.
    expect(Trainers::forUser($account)?->id)->toBe($trainer->id);
});

it('never takes over a CRM contact sharing the e-mail under another name', function () {
    $manager = userWithRole(Role::Commercial);
    $client = makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea@example.test', 'status' => 'client', 'responsible_user_id' => $manager->id]);
    $before = $client->fresh()->toArray();
    $account = userIn(tenant(), ['name' => 'Léa Martin', 'email' => 'lea@example.test']);

    $this->actingAs(trainersManager())->post('/trainers', ['user_id' => $account->id, 'status' => 'employee'])->assertRedirect();

    $person = Person::query()->findOrFail(Trainer::query()->sole()->person_id);
    expect($person->id)->not->toBe($client->id)->and($person->last_name)->toBe('Martin')
        ->and($client->fresh()->toArray())->toBe($before);
});

it('reuses the person whose name matches, accents and case aside', function () {
    $person = makePerson('Lefèvre', ['email' => 'jl@example.test']);
    $account = userIn(tenant(), ['name' => 'Jean LEFEVRE', 'email' => 'jl@example.test']);

    $this->actingAs(trainersManager())->post('/trainers', ['user_id' => $account->id, 'status' => 'employee'])->assertRedirect();

    expect(Trainer::query()->sole()->person_id)->toBe($person->id);
});

it('restores a trashed person with the e-mail and the name rather than duplicating her', function () {
    $person = makePerson('Corbeille', ['email' => 'corbeille@example.test']);
    $person->delete();

    $this->actingAs(trainersManager())->post('/trainers', ['person' => ['last_name' => 'corbeille', 'first_name' => '', 'email' => 'corbeille@example.test'], 'status' => 'employee'])
        ->assertRedirect();

    expect(Trainer::query()->sole()->person_id)->toBe($person->id)
        ->and($person->fresh()?->trashed())->toBeFalse()
        ->and(Person::withTrashed()->where('email', 'corbeille@example.test')->count())->toBe(1);
});

it('links the one TMS user having the e-mail of a trainer entered without account', function () {
    $account = userIn(tenant(), ['name' => 'Marc Petit', 'email' => 'marc@example.test']);

    $this->actingAs(trainersManager())->post('/trainers', ['person' => ['last_name' => 'Petit', 'first_name' => 'Marc', 'email' => 'MARC@example.test'], 'status' => 'employee'])
        ->assertRedirect();

    expect(Trainer::query()->sole()->user_id)->toBe($account->id);
});

it('keeps the people created for trainers out of the prospects and of the manager\'s fiches', function () {
    $manager = trainersManager();

    $this->actingAs($manager)->post('/trainers', ['person' => ['last_name' => 'Neutre', 'first_name' => 'Nina', 'email' => 'nina@example.test'], 'status' => 'employee'])
        ->assertRedirect();

    $person = Person::query()->findOrFail(Trainer::query()->sole()->person_id);
    expect($person->status->value)->not->toBe('prospect')
        ->and($person->created_by)->toBeNull()
        ->and($person->responsible_user_id)->toBeNull();
});

// ---------------------------------------------------------------- CRM fiche, merge, anonymisation, RLS

it('shows a « Formateur » block on the person fiche to whoever manages the trainers', function () {
    $trainer = makeTrainer();
    TrainerUnavailability::query()->create(['trainer_id' => $trainer->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

    $block = collect(FicheBlocks::for('person', $trainer->person_id, trainersManager()))->firstWhere('key', 'sessions.trainer');
    expect($block['title'] ?? null)->toBe('Formateur')
        ->and($block['rows'][0]['title'] ?? null)->toBe('Formateur salarié')
        ->and($block['rows'][0]['href'] ?? null)->toBe("/trainers/{$trainer->id}")
        ->and($block['rows'])->toHaveCount(2);

    expect(collect(FicheBlocks::for('person', $trainer->person_id, userWithPermissions([Permissions::SESSIONS_VIEW])))->firstWhere('key', 'sessions.trainer'))->toBeNull()
        ->and(collect(FicheBlocks::for('person', makePerson('Pas formateur')->id, trainersManager()))->firstWhere('key', 'sessions.trainer'))->toBeNull();
});

it('keeps a trainer person linked, repoints it on merge and refuses to merge two trainers', function () {
    $a = makeTrainer();
    $keep = makePerson('Gardée');
    expect(FicheReferences::linked('person', $a->person_id))->toBeTrue();

    FicheReferences::merge('person', $keep->id, $a->person_id);
    expect($a->refresh()->person_id)->toBe($keep->id);

    $b = makeTrainer(makePerson('Deuxième'));
    expect(fn () => FicheReferences::merge('person', $keep->id, $b->person_id))->toThrow(ValidationException::class);
    expect($b->refresh()->person_id)->not->toBe($keep->id);
});

it('clears the reasons of the unavailabilities of an anonymised trainer', function () {
    $trainer = makeTrainer();
    TrainerUnavailability::query()->create(['trainer_id' => $trainer->id, 'starts_at' => '2027-01-04 09:00', 'ends_at' => '2027-01-04 12:00', 'reason' => 'Rendez-vous médical']);

    FicheLinks::anonymise([$trainer->person_id]);

    expect(TrainerUnavailability::query()->sole()->reason)->toBeNull();
});

it('never journals the reason of an unavailability', function () {
    $trainer = makeTrainer();
    $u = TrainerUnavailability::query()->create(['trainer_id' => $trainer->id, 'starts_at' => '2027-01-04 09:00', 'ends_at' => '2027-01-04 12:00', 'reason' => 'Rendez-vous médical']);

    expect(json_encode(DB::table('audit_logs')->where('auditable_id', $u->id)->get()))->not->toContain('médical');
});

it('keeps the trainers of each organisme apart (RLS)', function () {
    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, fn () => Trainer::query()->create(['person_id' => makePerson('Ailleurs')->id, 'status' => 'employee']));
    inTenant();

    expect(Trainer::query()->count())->toBe(0);
    $this->actingAs(trainersManager())->get("/trainers/{$foreign->id}")->assertNotFound();
    $this->actingAs(trainersManager())->get('/trainers/pas-un-uuid')->assertNotFound();
});
