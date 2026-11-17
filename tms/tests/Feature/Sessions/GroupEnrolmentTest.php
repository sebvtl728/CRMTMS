<?php

use App\Modules\Catalogue\Training;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Enrolments\GroupEnrolment;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Task 6c: the title of a session, the enrolment of a group of trainees in
 * one go (later waves too), the planned hours of each trainee.
 */

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
});

const GRP_TITLE = 'Commis de cuisine – entrée du 30 novembre 2026';

function grpTraining(): Training
{
    return Training::query()->where('title', 'Commis de cuisine')->first()
        ?? Training::query()->create(['title' => 'Commis de cuisine', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '35.00']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function grpSession(array $overrides = []): TrainingSession
{
    return app(SessionWriter::class)->create([
        'training_id' => grpTraining()->id,
        'kind' => 'inter',
        'starts_on' => '2026-11-30',
        'ends_on' => '2027-03-05',
        'location_kind' => 'remote',
        'capacity' => 10,
        'status' => 'planned',
        ...$overrides,
    ]);
}

function grpDossier(?Organisation $organisation = null): Dossier
{
    $organisation ??= Organisation::query()->create(['name' => 'Brasserie du Port']);
    $training = grpTraining();

    return app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => $organisation->id,
        'account_person_id' => null,
        'das_id' => $training->das_id,
        'lines' => [[
            'kind' => 'training', 'training_id' => $training->id, 'label' => $training->title, 'quantity' => '1',
            'unit' => 'forfait', 'unit_price' => '6000', 'discount_percent' => '', 'pricing_mode' => 'per_trainee',
        ]],
    ]);
}

/**
 * @param  array<string, AccessScope|null>  $grants
 */
function grpUser(array $grants): User
{
    $user = userWithPermissions(array_keys($grants));
    $role = $user->roles()->firstOrFail();
    foreach ($grants as $permission => $scope) {
        if ($scope !== null) {
            DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => $permission, 'scope' => $scope->value]);
        }
    }
    Scopes::forget();

    return $user;
}

/**
 * @param  list<mixed>  $trainees
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function grpPayload(Dossier $dossier, array $trainees, array $extra = []): array
{
    return ['dossier_id' => $dossier->id, 'dossier_line_id' => $dossier->lines()->firstOrFail()->id, 'trainees' => $trainees, ...$extra];
}

// ---------------------------------------------------------------- title

it('stores the title of a session, shows it everywhere and searches it', function () {
    $this->actingAs(admin(true));
    $this->post('/sessions', [
        'training_id' => grpTraining()->id, 'kind' => 'inter', 'starts_on' => '2026-11-30', 'ends_on' => '2027-03-05',
        'location_kind' => 'remote', 'capacity' => '12', 'status' => 'planned', 'title' => '  '.GRP_TITLE.' ',
    ])->assertRedirect();
    $session = TrainingSession::query()->latest('created_at')->firstOrFail();
    $plain = grpSession();
    expect($session->title)->toBe(GRP_TITLE);

    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('session.title', GRP_TITLE)
        ->where('session.label', GRP_TITLE." ({$session->number})"));
    $this->get("/sessions/{$session->id}/edit")->assertInertia(fn (Assert $page) => $page->where('session.title', GRP_TITLE));
    // Without a title: « <formation> (S-…) », as before.
    $this->get("/sessions/{$plain->id}")->assertInertia(fn (Assert $page) => $page
        ->where('session.title', null)
        ->where('session.label', "Commis de cuisine ({$plain->number})"));

    // List search on the title (words of it), the calendar row.
    $this->get('/sessions?q='.urlencode('entrée du 30'))->assertInertia(fn (Assert $page) => $page
        ->has('sessions.data', 1)->where('sessions.data.0.id', $session->id)->where('sessions.data.0.title', GRP_TITLE));
    $this->get('/sessions/calendar?month=2026-12')->assertInertia(fn (Assert $page) => $page
        ->where('sessions.0.label', fn (string $label) => in_array($label, [GRP_TITLE." ({$session->number})", "Commis de cuisine ({$plain->number})"], true)));

    // Fiche block, dossier page (enrolment rows and session choices), history.
    $person = makePerson('Martin');
    $dossier = grpDossier();
    app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, $person->id);
    $this->get("/crm/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
        ->where('blocks.0.rows.0.title', GRP_TITLE)
        ->where('blocks.0.rows.0.subtitle', "Session {$session->number} · du 30/11/2026 au 05/03/2027"));
    $this->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('enrolments.0.session.label', GRP_TITLE." ({$session->number})")
        ->where('enrol.sessions.'.$dossier->lines()->firstOrFail()->id, fn ($options) => collect($options)->contains('title', GRP_TITLE))
        ->where('history.0.title', fn (string $t) => str_contains($t, GRP_TITLE)));

    // At most 150 characters, in French.
    $this->put("/sessions/{$session->id}", [
        'training_id' => grpTraining()->id, 'kind' => 'inter', 'starts_on' => '2026-11-30', 'ends_on' => '2027-03-05',
        'location_kind' => 'remote', 'capacity' => '12', 'status' => 'planned', 'title' => str_repeat('a', 151),
    ])->assertSessionHasErrors(['title' => 'L\'intitulé ne doit pas dépasser 150 caractères.']);
});

// ---------------------------------------------------------------- group enrolment

it('enrols a group of four in one go, one created on the spot, then a second wave a week later', function () {
    $organisation = Organisation::query()->create(['name' => 'Brasserie du Port']);
    $session = grpSession(['title' => GRP_TITLE]);
    $dossier = grpDossier($organisation);
    [$a, $b, $c] = [makePerson('Durand'), makePerson('Lemoine'), makePerson('Petit')];
    $this->actingAs(admin(true));

    $this->post("/sessions/{$session->id}/enrolments/group", grpPayload($dossier, [
        ['person_id' => $a->id], ['person_id' => $b->id], ['person_id' => $c->id],
        ['new_person' => ['last_name' => 'Nouveau', 'first_name' => 'Paul', 'email' => 'paul.nouveau@example.test', 'employee' => true, 'ref' => 'n1']],
    ], ['entered_on' => '2026-11-30']))->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', '4 stagiaires inscrits.');

    $created = Person::query()->where('last_name', 'Nouveau')->sole();
    expect($created->das()->pluck('reference_items.id')->all())->toBe([grpTraining()->das_id])
        ->and($created->affiliations()->pluck('organisation_id')->all())->toBe([$organisation->id]);
    $first = Enrolment::query()->where('training_session_id', $session->id)->orderBy('created_at')->get();
    expect($first)->toHaveCount(4)
        ->and($first->pluck('entered_on')->map->toDateString()->unique()->all())->toBe(['2026-11-30'])
        ->and($first->pluck('planned_hours')->unique()->all())->toBe(['35.00'])
        ->and($first->pluck('price_share')->all())->toBe(['1500.00', '1500.00', '1500.00', '1500.00']);

    // Second wave: two trainees arriving a week later, one with his own hours.
    $this->post("/sessions/{$session->id}/enrolments/group", grpPayload($dossier, [
        ['person_id' => makePerson('Robert')->id],
        ['person_id' => makePerson('Simon')->id, 'entered_on' => '2026-12-09', 'planned_hours' => '28'],
    ], ['entered_on' => '2026-12-07']))->assertSessionHas('status', '2 stagiaires inscrits.');

    $late = Enrolment::query()->where('training_session_id', $session->id)->orderBy('created_at')->get()->slice(4)->values();
    expect($late->map(fn (Enrolment $e) => [$e->entered_on?->toDateString(), $e->planned_hours])->all())
        ->toBe([['2026-12-07', '35.00'], ['2026-12-09', '28.00']])
        ->and(Enrolment::query()->where('dossier_id', $dossier->id)->pluck('price_share')->all())->toBe(array_fill(0, 6, '1000.00'));
});

it('keeps the places of an inter session: the fifth of a group goes on the waiting list', function () {
    $session = grpSession(['capacity' => 4]);
    $dossier = grpDossier();
    $this->actingAs(admin(true));

    $people = collect(range(1, 6))->map(fn (int $i) => ['person_id' => makePerson("Stagiaire {$i}")->id])->all();
    $this->post("/sessions/{$session->id}/enrolments/group", grpPayload($dossier, $people))
        ->assertSessionHas('status', "4 stagiaires inscrits, 2 en liste d'attente.");

    $rows = Enrolment::query()->where('training_session_id', $session->id)->orderBy('created_at')->orderBy('id')->get();
    expect($rows->map(fn (Enrolment $e) => [$e->status, $e->waitlist_position])->all())->toBe([
        [EnrolmentStatus::Enrolled, null], [EnrolmentStatus::Enrolled, null], [EnrolmentStatus::Enrolled, null], [EnrolmentStatus::Enrolled, null],
        [EnrolmentStatus::Waitlisted, 1], [EnrolmentStatus::Waitlisted, 2],
    ]);
});

it('writes nothing when one trainee of the group is refused, with French errors per trainee', function () {
    $session = grpSession();
    $dossier = grpDossier();
    $already = makePerson('Durand');
    app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, $already->id);
    makePerson('Homonyme', ['first_name' => 'Jean', 'email' => 'jean.homonyme@example.test']);
    $this->actingAs(admin(true));

    $this->post("/sessions/{$session->id}/enrolments/group", grpPayload($dossier, [
        ['person_id' => makePerson('Lemoine')->id],
        ['person_id' => $already->id],
        ['new_person' => ['last_name' => 'Homonyme', 'first_name' => 'Jean', 'email' => 'jean.homonyme@example.test', 'ref' => 'n1']],
        ['person_id' => makePerson('Petit')->id, 'entered_on' => 'demain'],
        ['person_id' => makePerson('Robert')->id, 'planned_hours' => '99'],
    ]))->assertSessionHasErrors([
        'trainees.1' => 'Ce stagiaire est déjà inscrit à cette session.',
        'trainees.2' => 'Des personnes similaires existent déjà : Jean Homonyme. Choisissez-la dans la recherche, ou cochez « Créer quand même ».',
        'trainees.3.entered_on' => 'Date d\'entrée invalide.',
        'trainees.4.planned_hours' => 'Les heures prévues ne peuvent pas dépasser celles de la session (35,00 h).',
    ]);
    expect(Enrolment::query()->count())->toBe(1)
        ->and(Person::query()->where('last_name', 'Homonyme')->count())->toBe(1)
        ->and(Person::query()->where('last_name', 'Lemoine')->sole()->id)->not->toBeIn(Enrolment::query()->pluck('person_id')->all());

    // « Créer quand même »: the homonym is created; the same ref twice is one person.
    $this->post("/sessions/{$session->id}/enrolments/group", grpPayload($dossier, [
        ['new_person' => ['last_name' => 'Homonyme', 'first_name' => 'Jean', 'email' => 'jean.homonyme@example.test', 'ref' => 'n1', 'confirm_duplicate' => true]],
    ]))->assertSessionHasNoErrors()->assertSessionHas('status', '1 stagiaire inscrit.');
    expect(Person::query()->where('last_name', 'Homonyme')->count())->toBe(2);

    $this->post("/sessions/{$session->id}/enrolments/group", grpPayload($dossier, [
        ['new_person' => ['last_name' => 'Unique', 'ref' => 'n9']],
        ['new_person' => ['last_name' => 'Unique', 'ref' => 'n9']],
    ]))->assertSessionHasErrors(['trainees.1' => 'Ce stagiaire est déjà inscrit à cette session.']);
    expect(Person::query()->where('last_name', 'Unique')->count())->toBe(0);

    // Empty group, more than 50.
    $this->post("/sessions/{$session->id}/enrolments/group", grpPayload($dossier, []))
        ->assertSessionHasErrors(['trainees' => 'Choisissez au moins un stagiaire.']);
});

it('lets an individual session take one trainee of a group only, and an intra session its entreprise only', function () {
    $individual = grpSession(['kind' => 'individual', 'capacity' => null]);
    $dossier = grpDossier();
    $this->actingAs(admin(true));

    $this->post("/sessions/{$individual->id}/enrolments/group", grpPayload($dossier, [['person_id' => makePerson('A')->id], ['person_id' => makePerson('B')->id]]))
        ->assertSessionHasErrors(['trainees' => 'Une session individuelle n\'accueille qu\'un stagiaire.']);
    expect(Enrolment::query()->count())->toBe(0);

    $intra = grpSession(['kind' => 'intra', 'capacity' => null, 'organisation_id' => Organisation::query()->create(['name' => 'Autre'])->id]);
    $this->post("/sessions/{$intra->id}/enrolments/group", grpPayload($dossier, [['person_id' => makePerson('C')->id]]))
        ->assertSessionHasErrors(['training_session_id']);
});

it('enrols a group within the rights: update right on the session, a dossier the user sees', function () {
    $session = grpSession();
    $dossier = grpDossier();
    $payload = grpPayload($dossier, [['person_id' => makePerson('A')->id]]);

    $this->actingAs(grpUser([Permissions::SESSIONS_VIEW => null, Permissions::DOSSIERS_VIEW => null]))
        ->post("/sessions/{$session->id}/enrolments/group", $payload)->assertForbidden();
    $this->actingAs(grpUser([Permissions::SESSIONS_VIEW => null, Permissions::SESSIONS_UPDATE => null, Permissions::DOSSIERS_VIEW => AccessScope::Own]))
        ->post("/sessions/{$session->id}/enrolments/group", $payload)->assertSessionHasErrors(['dossier_id' => 'Dossier introuvable.']);
    expect(Enrolment::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- hours per trainee

it('changes the planned hours of a trainee within the hours of the session, journaled and shown', function () {
    $session = grpSession();
    $dossier = grpDossier();
    $enrolment = app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, makePerson('Durand')->id);
    expect($enrolment->planned_hours)->toBe('35.00');
    $this->actingAs(admin(true));

    $this->put("/enrolments/{$enrolment->id}", ['planned_hours' => '17,5'])->assertSessionHasNoErrors();
    expect($enrolment->fresh()->planned_hours)->toBe('17.50');

    foreach (['0' => 'Les heures prévues doivent être supérieures à zéro.', '35,01' => 'Les heures prévues ne peuvent pas dépasser celles de la session (35,00 h).', 'abc' => 'Heures prévues invalides (ex. 21 ou 17,5).'] as $hours => $message) {
        $this->put("/enrolments/{$enrolment->id}", ['planned_hours' => (string) $hours])->assertSessionHasErrors(['planned_hours' => $message]);
    }
    $this->put("/enrolments/{$enrolment->id}", ['planned_hours' => '35'])->assertSessionHasNoErrors();
    expect($enrolment->fresh()->planned_hours)->toBe('35.00');
    // Empty: the hours of the session.
    $this->put("/enrolments/{$enrolment->id}", ['planned_hours' => '17,5']);
    $this->put("/enrolments/{$enrolment->id}", ['planned_hours' => ''])->assertSessionHasNoErrors();
    expect($enrolment->fresh()->planned_hours)->toBe('35.00');

    $this->put("/enrolments/{$enrolment->id}", ['planned_hours' => '28']);
    expect(AuditLog::query()->where('auditable_type', 'enrolment')->where('auditable_id', $enrolment->id)->where('event', 'updated')
        ->whereNotNull('new_values->planned_hours')->count())->toBeGreaterThanOrEqual(1);

    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page->where('enrolments.0.planned_hours', '28,00')->where('session.planned_hours', '35,00'));
    $this->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('history.0.title', "Durand — Heures prévues modifiées — session {$session->number} (Commis de cuisine)")
        ->where('history.0.body', '35,00 h → 28,00 h'));
});

it('takes any positive hours when the session has none', function () {
    $training = Training::query()->create(['title' => 'Sans heures', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);
    $session = app(SessionWriter::class)->create([
        'training_id' => $training->id, 'kind' => 'inter', 'starts_on' => '2026-11-30', 'ends_on' => '2026-12-04',
        'location_kind' => 'remote', 'capacity' => 10, 'status' => 'planned',
    ]);
    $dossier = app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => Organisation::query()->create(['name' => 'X'])->id, 'account_person_id' => null, 'das_id' => $training->das_id,
        'lines' => [['kind' => 'training', 'training_id' => $training->id, 'label' => 'Sans heures', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '100', 'discount_percent' => '', 'pricing_mode' => 'group']],
    ]);
    $enrolment = app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, makePerson('A')->id, ['planned_hours' => '12']);
    expect($enrolment->planned_hours)->toBe('12.00');
});

// ---------------------------------------------------------------- review fixes

it('never changes the trainees\' hours from the session form: the hours are those of the modules (0.8.0)', function () {
    $session = grpSession();
    $dossier = grpDossier();
    $line = $dossier->lines()->firstOrFail();
    $full = app(Enrolments::class)->enrol($dossier, $line, $session, makePerson('A')->id);
    $part = app(Enrolments::class)->enrol($dossier, $line, $session, makePerson('B')->id, ['planned_hours' => '20']);
    $hours = $session->fresh()->planned_hours;
    $this->actingAs(admin(true));

    $this->put("/sessions/{$session->id}", [
        'title' => '', 'training_id' => grpTraining()->id, 'kind' => 'inter', 'starts_on' => '2026-11-30', 'ends_on' => '2027-03-05',
        'location_kind' => 'remote', 'capacity' => '10', 'status' => 'planned', 'planned_hours' => '28',
    ])->assertSessionHasNoErrors();

    expect($session->fresh()->planned_hours)->toBe($hours)
        ->and($full->fresh()->planned_hours)->toBe($hours)->and($part->fresh()->planned_hours)->toBe('20.00');
});

it('computes the shares of the line once for the whole group, with the same result', function () {
    $session = grpSession();
    $dossier = grpDossier();
    $this->actingAs(admin(true));
    $updates = 0;
    DB::listen(function ($query) use (&$updates): void {
        if (str_starts_with($query->sql, 'update "enrolments" set "price_share"')) {
            $updates++;
        }
    });

    $this->post("/sessions/{$session->id}/enrolments/group", grpPayload($dossier, collect(range(1, 3))->map(fn (int $i) => ['person_id' => makePerson("P{$i}")->id])->all()))
        ->assertSessionHas('status', '3 stagiaires inscrits.');

    expect(Enrolment::query()->orderBy('created_at')->orderBy('id')->pluck('price_share')->all())->toBe(['2000.00', '2000.00', '2000.00'])
        ->and($updates)->toBe(3);
});

it('takes the common entry day for a trainee whose own day is empty', function () {
    $session = grpSession();
    $dossier = grpDossier();
    app(GroupEnrolment::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, [
        ['person_id' => makePerson('A')->id, 'entered_on' => ''],
        ['person_id' => makePerson('B')->id, 'entered_on' => '  '],
        ['person_id' => makePerson('C')->id, 'entered_on' => '2026-12-14'],
    ], '2026-12-07');

    expect(Enrolment::query()->orderBy('created_at')->orderBy('id')->get()->map(fn (Enrolment $e) => $e->entered_on?->toDateString())->all())
        ->toBe(['2026-12-07', '2026-12-07', '2026-12-14']);
});

it('rate-limits the group enrolment with the named limiter of the trainee searches', function () {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'sessions.enrolments.group');
    expect($route->gatherMiddleware())->toContain('throttle:sessions-trainees');
});
