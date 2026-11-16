<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Catalogue\Training;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierSchedule;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
});

function enrTraining(string $title = 'HACCP 3 jours', string $das = 'Hôtellerie-restauration'): Training
{
    return Training::query()->where('title', $title)->first()
        ?? Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, $das)->id, 'duration_hours' => '21.00']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function enrSession(?Training $training = null, array $overrides = []): TrainingSession
{
    return app(SessionWriter::class)->create([
        'training_id' => ($training ?? enrTraining())->id,
        'kind' => 'inter',
        'organisation_id' => null,
        'starts_on' => '2026-12-07',
        'ends_on' => '2026-12-09',
        'planned_hours' => '',
        'location_kind' => 'remote',
        'location_text' => null,
        'capacity' => 10,
        'responsible_user_id' => null,
        'status' => 'planned',
        'notes' => null,
        ...$overrides,
    ]);
}

/**
 * A dossier of $organisation with one training line of $training.
 *
 * @param  array<string, mixed>  $line
 */
function enrDossier(?Organisation $organisation = null, ?Training $training = null, array $line = []): Dossier
{
    $organisation ??= Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $training ??= enrTraining();

    return app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => $organisation->id,
        'account_person_id' => null,
        'das_id' => $training->das_id,
        'lines' => [[
            'kind' => 'training', 'training_id' => $training->id, 'label' => $training->title, 'quantity' => '1',
            'unit' => 'forfait', 'unit_price' => '1000', 'discount_percent' => '', 'pricing_mode' => 'per_trainee', ...$line,
        ]],
    ]);
}

function enrLine(Dossier $dossier): DossierLine
{
    return $dossier->lines()->firstOrFail();
}

/**
 * @param  array<string, mixed>  $extra
 */
function enrol(Dossier $dossier, TrainingSession $session, ?Person $person = null, array $extra = []): Enrolment
{
    return app(Enrolments::class)->enrol($dossier, enrLine($dossier), $session, ($person ?? makePerson('Stagiaire '.Str::random(6)))->id, $extra);
}

function enrReason(string $label = 'Maladie'): ReferenceItem
{
    return crmItem(ReferenceList::AbandonReason, $label);
}

/**
 * A user of a custom role holding exactly $grants (permission => scope; null: « all »).
 *
 * @param  array<string, AccessScope|null>  $grants
 */
function enrUser(array $grants): User
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

// ---------------------------------------------------------------- waiting list (Review Focus 2)

it('puts the next enrolment of a full inter session on the waiting list, in order of arrival', function () {
    $session = enrSession(null, ['capacity' => 2]);
    $dossier = enrDossier();

    $first = enrol($dossier, $session);
    $second = enrol($dossier, $session);
    $third = enrol($dossier, $session);
    $fourth = enrol(enrDossier(Organisation::query()->create(['name' => 'Autre hôtel'])), $session);

    expect($first->status)->toBe(EnrolmentStatus::Enrolled)->and($first->waitlist_position)->toBeNull()
        ->and($second->status)->toBe(EnrolmentStatus::Enrolled)
        ->and($third->status)->toBe(EnrolmentStatus::Waitlisted)->and($third->waitlist_position)->toBe(1)
        ->and($fourth->status)->toBe(EnrolmentStatus::Waitlisted)->and($fourth->waitlist_position)->toBe(2)
        ->and($first->planned_hours)->toBe('21.00')
        ->and($first->entered_on->toDateString())->toBe('2026-12-07');

    // A confirmed trainee holds his place too.
    app(Enrolments::class)->confirm($first);
    expect(enrol($dossier, $session)->status)->toBe(EnrolmentStatus::Waitlisted);
});

it('promotes nobody when a place is freed, and promotes by hand while a place is free', function () {
    $session = enrSession(null, ['capacity' => 1]);
    $dossier = enrDossier();
    $enrolments = app(Enrolments::class);
    $first = enrol($dossier, $session);
    $waiting = enrol($dossier, $session);

    // Full: no promotion.
    expect(fn () => $enrolments->promote($waiting))->toThrow(UserFacingHttpException::class, Enrolments::FULL);

    $enrolments->cancel($first);
    expect($first->fresh()->status)->toBe(EnrolmentStatus::Cancelled)
        ->and($waiting->fresh()->status)->toBe(EnrolmentStatus::Waitlisted);

    $enrolments->promote($waiting);
    expect($waiting->fresh()->status)->toBe(EnrolmentStatus::Enrolled)->and($waiting->fresh()->waitlist_position)->toBeNull();

    expect(fn () => $enrolments->promote($waiting))->toThrow(UserFacingHttpException::class, Enrolments::NOT_WAITLISTED);
});

it('lets an intra session take the dossiers of its entreprise only', function () {
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $other = Organisation::query()->create(['name' => 'Autre hôtel']);
    $session = enrSession(null, ['kind' => 'intra', 'organisation_id' => $hotel->id, 'capacity' => null]);

    expect(enrol(enrDossier($hotel), $session)->status)->toBe(EnrolmentStatus::Enrolled);

    try {
        enrol(enrDossier($other), $session);
        $this->fail('An intra session took the dossier of another contractant.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('training_session_id');
    }

    // A particulier dossier is another contractant too.
    $individual = makePerson('Durand', ['is_individual' => true]);
    $dossier = app(DossierWriter::class)->create([
        'company_id' => Company::query()->where('is_default', true)->firstOrFail()->id,
        'account_person_id' => $individual->id,
        'das_id' => enrTraining()->das_id,
        'lines' => [['kind' => 'training', 'training_id' => enrTraining()->id, 'label' => 'HACCP', 'quantity' => '1', 'unit' => 'stagiaire', 'unit_price' => '500', 'discount_percent' => '']],
    ]);
    expect(fn () => enrol($dossier, $session, $individual))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------- refusals

it('refuses a trainee twice on a session, unless the first enrolment is cancelled', function () {
    $session = enrSession();
    $dossier = enrDossier();
    $person = makePerson('Martin');
    $first = enrol($dossier, $session, $person);

    try {
        enrol($dossier, $session, $person);
        $this->fail('Enrolled twice.');
    } catch (ValidationException $e) {
        expect($e->errors()['person_id'][0])->toBe('Ce stagiaire est déjà inscrit à cette session.');
    }

    app(Enrolments::class)->cancel($first);
    expect(enrol($dossier, $session, $person)->status)->toBe(EnrolmentStatus::Enrolled);
});

it('refuses a session of another training than the line, a fee line and a cancelled session', function () {
    $dossier = enrDossier();
    $other = enrSession(enrTraining('Anglais professionnel', 'Compétences transversales'));

    try {
        enrol($dossier, $other);
        $this->fail('Enrolled on another training.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('training_session_id');
    }

    $fees = app(DossierWriter::class)->create([
        'company_id' => Company::query()->where('is_default', true)->firstOrFail()->id,
        'account_organisation_id' => $dossier->account_organisation_id,
        'das_id' => $dossier->das_id,
        'lines' => [['kind' => 'fee', 'label' => 'Frais de déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '80', 'discount_percent' => '']],
    ]);
    try {
        enrol($fees, enrSession());
        $this->fail('Enrolled on a fee line.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('dossier_line_id');
    }

    $cancelled = enrSession();
    app(SessionWriter::class)->cancel($cancelled, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);
    expect(fn () => enrol($dossier, $cancelled))->toThrow(UserFacingHttpException::class, Enrolments::SESSION_CLOSED);
});

// ---------------------------------------------------------------- abandon

it('records an abandon with its leaving day and reason, the share kept', function () {
    $session = enrSession();
    $dossier = enrDossier();
    $a = enrol($dossier, $session);
    enrol($dossier, $session);
    enrol($dossier, $session);
    $this->actingAs(admin(true));

    // Both required.
    $this->postJson("/enrolments/{$a->id}/abandon", [])->assertJsonValidationErrors(['left_on', 'abandon_reason_id']);
    $this->postJson("/enrolments/{$a->id}/abandon", ['left_on' => '2026-12-08'])->assertJsonValidationErrors(['abandon_reason_id']);
    $this->postJson("/enrolments/{$a->id}/abandon", ['left_on' => '2026-12-08', 'abandon_reason_id' => crmItem(ReferenceList::BpfCategory, 'Autres')->id])
        ->assertJsonValidationErrors(['abandon_reason_id']);
    // Not before the entry.
    $this->postJson("/enrolments/{$a->id}/abandon", ['left_on' => '2026-12-01', 'abandon_reason_id' => enrReason()->id])->assertJsonValidationErrors(['left_on']);

    $this->post("/enrolments/{$a->id}/abandon", ['left_on' => '2026-12-08', 'abandon_reason_id' => enrReason()->id])->assertRedirect();

    $fresh = $a->fresh();
    expect($fresh->status)->toBe(EnrolmentStatus::Abandoned)
        ->and($fresh->left_on->toDateString())->toBe('2026-12-08')
        ->and($fresh->abandon_reason_id)->toBe(enrReason()->id)
        ->and($fresh->price_share)->toBe('333.34');

    // An abandon does not abandon again.
    expect(fn () => app(Enrolments::class)->abandon($fresh, CarbonImmutable::parse('2026-12-09'), enrReason()->id))
        ->toThrow(UserFacingHttpException::class, Enrolments::NOT_ATTENDING);
});

it('journals the adaptation needs and the notes without their value', function () {
    $enrolment = enrol(enrDossier(), enrSession(), null, ['adaptation_needs' => 'Fauteuil roulant', 'notes' => 'Allergie au gluten']);

    $log = AuditLog::query()->where('auditable_id', $enrolment->id)->where('event', 'created')->firstOrFail();
    expect(json_encode($log->new_values))->not->toContain('Fauteuil')->not->toContain('gluten')
        ->and($log->new_values['adaptation_needs'])->toBe('[donnée personnelle]');
});

// ---------------------------------------------------------------- session and dossier locks

it('locks the training, type and entreprise of a session with enrolments, and keeps it out of the trash', function () {
    $session = enrSession();
    $enrolment = enrol(enrDossier(), $session);
    $writer = app(SessionWriter::class);
    $payload = fn (array $o) => [
        'training_id' => $session->training_id, 'kind' => 'inter', 'organisation_id' => null, 'starts_on' => '2026-12-07',
        'ends_on' => '2026-12-10', 'planned_hours' => '21', 'location_kind' => 'remote', 'location_text' => null,
        'capacity' => 10, 'responsible_user_id' => null, 'status' => 'planned', 'notes' => null, ...$o,
    ];

    // Dates still change.
    $writer->update($session, $payload([]));
    expect($session->fresh()->ends_on->toDateString())->toBe('2026-12-10');

    expect(fn () => $writer->update($session, $payload(['training_id' => enrTraining('Anglais professionnel', 'Compétences transversales')->id])))
        ->toThrow(UserFacingHttpException::class, SessionWriter::LOCKED);
    $hotel = Organisation::query()->create(['name' => 'Hôtel']);
    expect(fn () => $writer->update($session, $payload(['kind' => 'intra', 'organisation_id' => $hotel->id, 'capacity' => null])))
        ->toThrow(UserFacingHttpException::class, SessionWriter::LOCKED);

    $this->actingAs(admin(true))->delete("/sessions/{$session->id}")->assertStatus(409);
    expect($session->fresh()->trashed())->toBeFalse();

    // Once the enrolment is cancelled, both are possible.
    app(Enrolments::class)->cancel($enrolment);
    $this->delete("/sessions/{$session->id}")->assertRedirect();
    expect(TrainingSession::withTrashed()->find($session->id)->trashed())->toBeTrue();
});

it('keeps a dossier line with enrolments from being deleted or given another training', function () {
    $session = enrSession();
    $dossier = enrDossier();
    $line = enrLine($dossier);
    enrol($dossier, $session);
    $writer = app(DossierWriter::class);
    $payload = fn (array $lines) => [
        'company_id' => $dossier->company_id, 'account_organisation_id' => $dossier->account_organisation_id,
        'das_id' => $dossier->das_id, 'lines' => $lines,
    ];
    $kept = ['id' => $line->id, 'kind' => 'training', 'training_id' => $line->training_id, 'label' => $line->label, 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '1000', 'discount_percent' => '', 'pricing_mode' => 'per_trainee'];
    $fee = ['kind' => 'fee', 'label' => 'Repas', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '50', 'discount_percent' => ''];

    try {
        $writer->update($dossier, $payload([$fee]));
        $this->fail('A line with enrolments was deleted.');
    } catch (ValidationException $e) {
        expect($e->errors()['lines'][0])->toContain('a des stagiaires inscrits');
    }
    expect(fn () => $writer->update($dossier, $payload([[...$kept, 'training_id' => enrTraining('Anglais professionnel', 'Compétences transversales')->id]])))
        ->toThrow(ValidationException::class);
    expect(DossierLine::query()->find($line->id))->not->toBeNull();

    // Its price still changes, and the shares follow.
    $writer->update($dossier, $payload([[...$kept, 'unit_price' => '600']]));
    expect(Enrolment::query()->where('dossier_line_id', $line->id)->value('price_share'))->toBe('600.00');
});

it('refuses with a 409 a dossier form sending a line deleted meanwhile', function () {
    $dossier = enrDossier();
    $line = enrLine($dossier);
    $payload = [
        'company_id' => $dossier->company_id, 'account_organisation_id' => $dossier->account_organisation_id, 'das_id' => $dossier->das_id,
        'lines' => [['id' => $line->id, 'kind' => 'training', 'training_id' => $line->training_id, 'label' => 'HACCP', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '900', 'discount_percent' => '']],
    ];
    // The line goes once validated, before the lock (another request).
    $gone = false;
    DB::listen(function ($query) use (&$gone, $line): void {
        if (! $gone && str_contains($query->sql, 'for update')) {
            $gone = true;
            DB::table('dossier_lines')->where('id', $line->id)->delete();
        }
    });

    expect(fn () => app(DossierWriter::class)->update($dossier, $payload))->toThrow(UserFacingHttpException::class, DossierWriter::LINE_GONE);
});

// ---------------------------------------------------------------- HTTP: enrol, search, create

it('enrols from the session screen within the rights and scopes', function () {
    $session = enrSession(null, ['capacity' => 1]);
    $dossier = enrDossier();
    $person = makePerson('Martin');
    $this->actingAs(admin(true));

    $this->post("/sessions/{$session->id}/enrolments", ['dossier_id' => $dossier->id, 'dossier_line_id' => enrLine($dossier)->id, 'person_id' => $person->id, 'adaptation_needs' => 'Malvoyant'])
        ->assertRedirect()->assertSessionHas('status', 'Stagiaire inscrit.');
    $this->post("/sessions/{$session->id}/enrolments", ['dossier_id' => $dossier->id, 'dossier_line_id' => enrLine($dossier)->id, 'person_id' => makePerson('Durand')->id])
        ->assertSessionHas('status', "Session complète : stagiaire placé en liste d'attente (position 1).");

    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->has('enrolments', 2)
        ->where('enrolments.0.person.label', 'Martin')
        ->where('enrolments.0.dossier.number', $dossier->number)
        ->where('enrolments.1.status', 'waitlisted'));

    // Without sessions.sessions.update: refused.
    $viewer = enrUser([Permissions::SESSIONS_VIEW => null, Permissions::DOSSIERS_VIEW => null]);
    $this->actingAs($viewer)->post("/sessions/{$session->id}/enrolments", ['dossier_id' => $dossier->id, 'dossier_line_id' => enrLine($dossier)->id, 'person_id' => makePerson('Petit')->id])
        ->assertForbidden();

    // A dossier he does not see is not found.
    $editor = enrUser([Permissions::SESSIONS_VIEW => null, Permissions::SESSIONS_UPDATE => null, Permissions::DOSSIERS_VIEW => AccessScope::Own]);
    $this->actingAs($editor)->postJson("/sessions/{$session->id}/enrolments", ['dossier_id' => $dossier->id, 'dossier_line_id' => enrLine($dossier)->id, 'person_id' => makePerson('Petit')->id])
        ->assertJsonValidationErrors(['dossier_id']);

    // On the session page, his trainees' names are shown, not the dossier nor the share.
    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('enrolments.0.person.label', 'Martin')->where('enrolments.0.dossier', null)->where('enrolments.0.price_share', null));
});

it('finds an existing person of another DAS for a user limited to his DAS, identity only (Review Focus 5)', function () {
    $session = enrSession();
    $languages = crmItem(ReferenceList::Das, 'Compétences transversales');
    $person = makePerson('Lefèvre', ['first_name' => 'Anne', 'birth_date' => '1990-04-02', 'email' => 'anne.lefevre@example.test', 'notes' => 'Secret']);
    $person->das()->attach($languages->id, ['tenant_id' => tenant()->id]);
    affiliate($person, Organisation::query()->create(['name' => 'Brasserie Lyonnaise']));

    $me = enrUser([Permissions::SESSIONS_VIEW => AccessScope::Das, Permissions::SESSIONS_UPDATE => AccessScope::Das, 'crm.fiches.view' => AccessScope::Das]);
    followDas($me, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));

    $found = $this->actingAs($me)->getJson("/sessions/{$session->id}/trainees?q=lefevre")->assertOk()->json('personnes');
    expect($found)->toHaveCount(1)
        ->and(array_keys($found[0]))->toBe(['id', 'last_name', 'first_name', 'birth_date', 'organisation_label'])
        ->and($found[0])->toMatchArray(['id' => $person->id, 'birth_date' => '1990-04-02', 'organisation_label' => 'Brasserie Lyonnaise']);

    // Not with the view right only.
    $this->actingAs(enrUser([Permissions::SESSIONS_VIEW => null]))->getJson("/sessions/{$session->id}/trainees?q=lefevre")->assertForbidden();
});

it('creates a trainee with the DAS of the training, and offers the similar people first', function () {
    $session = enrSession();
    $this->actingAs(admin(true));

    $id = $this->postJson("/sessions/{$session->id}/trainees", ['last_name' => 'Nouveau', 'first_name' => 'Paul', 'email' => 'paul.nouveau@example.test'])->assertCreated()->json('personne.id');
    $person = Person::query()->findOrFail($id);
    expect($person->das()->pluck('reference_items.id')->all())->toBe([crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);

    $this->postJson("/sessions/{$session->id}/trainees", ['last_name' => 'Nouveau', 'first_name' => 'Paul', 'email' => 'paul.nouveau@example.test'])
        ->assertStatus(409)->assertJsonPath('candidates.0.id', $id);
    $this->postJson("/sessions/{$session->id}/trainees", ['last_name' => 'Nouveau', 'first_name' => 'Paul', 'email' => 'paul.nouveau@example.test', 'confirm_duplicate' => true])->assertCreated();
});

it('confirms, cancels and updates an enrolment from the screens', function () {
    $enrolment = enrol(enrDossier(), enrSession());
    $this->actingAs(admin(true));

    $this->post("/enrolments/{$enrolment->id}/confirm")->assertRedirect();
    expect($enrolment->fresh()->status)->toBe(EnrolmentStatus::Confirmed);
    $this->post("/enrolments/{$enrolment->id}/confirm")->assertStatus(409);

    $bpf = crmItem(ReferenceList::BpfCategory, 'Autres');
    $this->put("/enrolments/{$enrolment->id}", ['bpf_category_id' => $bpf->id, 'adaptation_needs' => 'Interprète LSF'])->assertRedirect();
    expect($enrolment->fresh()->bpf_category_id)->toBe($bpf->id)->and($enrolment->fresh()->adaptation_needs)->toBe('Interprète LSF');

    $this->post("/enrolments/{$enrolment->id}/cancel")->assertRedirect();
    expect($enrolment->fresh()->status)->toBe(EnrolmentStatus::Cancelled);
    $this->put("/enrolments/{$enrolment->id}", ['notes' => 'x'])->assertStatus(409);

    // Outside the session view scope: 404.
    $this->actingAs(enrUser([Permissions::SESSIONS_VIEW => AccessScope::Own, Permissions::SESSIONS_UPDATE => AccessScope::Own]))
        ->post("/enrolments/{$enrolment->id}/promote")->assertNotFound();
});

// ---------------------------------------------------------------- fiche blocks

it('lists on the person fiche her enrolments on the sessions the user sees', function () {
    $person = makePerson('Martin');
    $mine = enrSession();
    $theirs = enrSession(enrTraining('Anglais professionnel', 'Compétences transversales'));
    enrol(enrDossier(), $mine, $person);
    enrol(enrDossier(null, enrTraining('Anglais professionnel', 'Compétences transversales')), $theirs, $person);

    $this->actingAs(admin(true))->get("/crm/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
        ->where('blocks.0.key', 'sessions.enrolments')
        ->where('blocks.0.title', 'Formations suivies')
        ->has('blocks.0.rows', 2));

    $das = enrUser(['crm.fiches.view' => null, Permissions::SESSIONS_VIEW => AccessScope::Das]);
    followDas($das, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    $this->actingAs($das)->get("/crm/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
        ->has('blocks.0.rows', 1)
        ->where('blocks.0.rows.0.title', 'HACCP 3 jours')
        ->where('blocks.0.rows.0.badge', 'Inscrit')
        ->where('blocks.0.rows.0.href', "/sessions/{$mine->id}"));

    // Without sessions.sessions.view: no block.
    $this->actingAs(enrUser(['crm.fiches.view' => null]))->get("/crm/people/{$person->id}")->assertInertia(fn (Assert $page) => $page->has('blocks', 0));
});

it('lists on the entreprise fiche its dossiers the user sees', function () {
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $dossier = enrDossier($hotel);
    enrDossier(Organisation::query()->create(['name' => 'Autre']));

    $this->actingAs(admin(true))->get("/crm/organisations/{$hotel->id}")->assertInertia(fn (Assert $page) => $page
        ->where('blocks.0.key', 'sessions.dossiers')
        ->has('blocks.0.rows', 1)
        ->where('blocks.0.rows.0.title', "Dossier {$dossier->number}")
        ->where('blocks.0.rows.0.meta', '1 000,00 € HT')
        ->where('blocks.0.rows.0.badge', 'Créé'));

    $own = enrUser(['crm.fiches.view' => null, Permissions::DOSSIERS_VIEW => AccessScope::Own]);
    $this->actingAs($own)->get("/crm/organisations/{$hotel->id}")->assertInertia(fn (Assert $page) => $page->has('blocks.0.rows', 0));
    $this->actingAs(enrUser(['crm.fiches.view' => null]))->get("/crm/organisations/{$hotel->id}")->assertInertia(fn (Assert $page) => $page->has('blocks', 0));
});

// ---------------------------------------------------------------- schedule, list state, export

it('computes the state of a dossier from the sessions of its enrolments, in the list filter too', function () {
    $running = enrDossier();
    enrol($running, enrSession(null, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-20']));
    $done = enrDossier();
    enrol($done, enrSession(null, ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-03']));
    $later = enrDossier();
    enrol($later, enrSession(null, ['starts_on' => '2026-12-07', 'ends_on' => '2026-12-09']));
    $cancelledOnly = enrDossier();
    app(Enrolments::class)->cancel(enrol($cancelledOnly, enrSession(null, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-20'])));

    expect($running->effectiveStatus(Dossier::today())->value)->toBe('running')
        ->and($done->effectiveStatus(Dossier::today())->value)->toBe('done')
        ->and($later->effectiveStatus(Dossier::today())->value)->toBe('created');

    $listed = fn (string $status) => collect($this->get("/dossiers?status={$status}")->viewData('page')['props']['dossiers']['data'])->pluck('id')->sort()->values()->all();
    $this->actingAs(admin(true));
    expect($listed('running'))->toBe([$running->id])
        ->and($listed('done'))->toBe([$done->id])
        ->and($listed('created'))->toBe(collect([$later->id, $cancelledOnly->id])->sort()->values()->all());
});

it('exports the dossiers without a query per row, and answers the export pre-flight', function () {
    $this->actingAs(admin(true));
    $hotel = fn (int $i) => enrol(enrDossier(Organisation::query()->create(['name' => "Hôtel {$i}"])), enrSession(null, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-20']));
    $hotel(0);
    $count = function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $csv = $this->get('/dossiers/export')->assertOk()->streamedContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$csv, $queries];
    };
    $count(); // Permissions cached.
    [, $one] = $count();
    $hotel(1);
    $hotel(2);
    [$csv, $three] = $count();

    expect($csv)->toContain('1 000,00')->toContain('En cours')->toContain('Hôtel 2')
        // The same queries for one row or three (one per source and chunk).
        ->and($three)->toBe($one);

    $this->getJson('/dossiers/export/check')->assertOk()->assertJson(['rows' => 3, 'allowed' => true, 'message' => null]);
    config(['tms.crm.export_max_rows' => 2]);
    $this->getJson('/dossiers/export/check')->assertOk()->assertJsonPath('allowed', false);
});

// ---------------------------------------------------------------- review round 1

it('rate-limits the trainee search and creation with a named limiter', function () {
    foreach (['sessions.trainees', 'sessions.trainees.store'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:sessions-trainees');
    }
});

it('rate-limits every identity search of the module and keeps its answers out of any cache', function () {
    foreach (['dossiers.people', 'dossiers.organisations', 'sessions.organisations'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:sessions-trainees');
    }
    $this->actingAs(admin(true));
    foreach (['/dossiers/people?q=du', '/dossiers/organisations?q=du', '/sessions/organisations?q=du'] as $url) {
        expect($this->getJson($url)->assertOk()->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('cancels the enrolments of a cancelled dossier and refuses to trash a dossier with trainees', function () {
    $session = enrSession();
    $dossier = enrDossier();
    $other = enrDossier(Organisation::query()->create(['name' => 'Autre']));
    $a = enrol($dossier, $session);
    $b = enrol($dossier, $session);
    $kept = enrol($other, $session);
    $this->actingAs(admin(true));

    $this->delete("/dossiers/{$dossier->id}")->assertStatus(409);
    expect($dossier->fresh()->trashed())->toBeFalse();

    $this->post("/dossiers/{$dossier->id}/cancel", ['cancelled_reason_id' => crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id])->assertRedirect();
    expect($a->fresh()->status)->toBe(EnrolmentStatus::Cancelled)->and($b->fresh()->status)->toBe(EnrolmentStatus::Cancelled)
        ->and($a->fresh()->price_share)->toBeNull()
        ->and($kept->fresh()->status)->toBe(EnrolmentStatus::Enrolled)
        ->and(AuditLog::query()->where('auditable_id', $a->id)->where('event', 'updated')->exists())->toBeTrue();

    $this->delete("/dossiers/{$dossier->id}")->assertRedirect();
    expect(Dossier::withTrashed()->find($dossier->id)->trashed())->toBeTrue();
});

it('cancels the enrolments of a cancelled session, shows it on the fiche and offers no action', function () {
    $session = enrSession();
    $other = enrSession();
    $dossier = enrDossier();
    $person = makePerson('Martin');
    $a = enrol($dossier, $session, $person);
    $elsewhere = enrol($dossier, $other);
    expect($elsewhere->fresh()->price_share)->toBe('500.00');

    app(SessionWriter::class)->cancel($session, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);
    expect($a->fresh()->status)->toBe(EnrolmentStatus::Cancelled)
        // The shares of the line follow.
        ->and($elsewhere->fresh()->price_share)->toBe('1000.00');

    $this->actingAs(admin(true))->get("/crm/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
        ->where('blocks.0.rows.0.badge', 'Session annulée'));
    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('enrolments.0.session.cancelled', true)->where('enrolments.0.can_update', false));
});

it('sends the adaptation needs and the notes only to who may update the enrolment', function () {
    $session = enrSession();
    enrol(enrDossier(), $session, makePerson('Martin'), ['adaptation_needs' => 'Fauteuil roulant', 'notes' => 'Note']);

    $this->actingAs(enrUser([Permissions::SESSIONS_VIEW => null, Permissions::DOSSIERS_VIEW => null]))
        ->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('enrolments.0.person.label', 'Martin')
        ->where('enrolments.0.adaptation_needs', null)->where('enrolments.0.notes', null));

    $this->actingAs(admin(true))->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('enrolments.0.adaptation_needs', 'Fauteuil roulant')->where('enrolments.0.notes', 'Note'));
});

it('keeps the stored values of the keys absent from an update', function () {
    $bpf = crmItem(ReferenceList::BpfCategory, 'Autres');
    $enrolment = enrol(enrDossier(), enrSession(), null, ['adaptation_needs' => 'Malvoyant', 'notes' => 'Note', 'bpf_category_id' => $bpf->id]);

    app(Enrolments::class)->update($enrolment, ['notes' => 'Autre note']);
    $fresh = $enrolment->fresh();
    expect($fresh->notes)->toBe('Autre note')->and($fresh->adaptation_needs)->toBe('Malvoyant')
        ->and($fresh->bpf_category_id)->toBe($bpf->id)->and($fresh->entered_on->toDateString())->toBe('2026-12-07');

    // A key sent empty clears it.
    app(Enrolments::class)->update($enrolment, ['adaptation_needs' => '']);
    expect($enrolment->fresh()->adaptation_needs)->toBeNull()->and($enrolment->fresh()->notes)->toBe('Autre note');
});

it('leaves the waiting list out of the span of a dossier', function () {
    $full = enrSession(null, ['capacity' => 1, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-20']);
    enrol(enrDossier(Organisation::query()->create(['name' => 'Premier'])), $full);
    $waiting = enrDossier();
    enrol($waiting, $full);

    expect(DossierSchedule::spans([$waiting->id]))->toBe([])
        ->and($waiting->effectiveStatus(Dossier::today())->value)->toBe('created');
});
