<?php

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\DossierCreation;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Companies\Company;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Sessions\SessionWriter;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-06 15:00', 'Europe/Paris'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** An admitted candidate enrolled on a session of two séances (4th: present, 11th: to come). */
function fuAdmittedTrainee(): Candidature
{
    $session = app(SessionWriter::class)->create([
        'training_id' => adTraining()->id, 'kind' => 'inter', 'organisation_id' => null,
        'starts_on' => '2027-01-04', 'ends_on' => '2027-06-30', 'planned_hours' => '', 'location_kind' => 'remote',
        'location_text' => null, 'capacity' => 10, 'responsible_user_id' => null, 'status' => 'planned', 'notes' => null,
    ]);
    $module = SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->firstOrFail();
    app(SessionModules::class)->switchMode($module, 'seances');
    $first = app(Seances::class)->create($session, ['session_module_id' => $module->id, 'date' => '2027-01-04', 'starts_at' => '09:00', 'ends_at' => '12:00']);
    app(Seances::class)->create($session, ['session_module_id' => $module->id, 'date' => '2027-01-11', 'starts_at' => '09:00', 'ends_at' => '12:00']);

    $c = adCandidature(['person_id' => adPerson('Durand', ['is_individual' => true])->id, 'target_session_id' => $session->id]);
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Admitted, admin());
    $c = $c->fresh();
    app(DossierCreation::class)->create($c, app(DossierCreation::class)->defaults($c), admin());
    $enrolment = Enrolment::query()->where('person_id', $c->person_id)->firstOrFail();
    app(Attendances::class)->record($first, $enrolment->id, 'present', null, null);

    return $c->fresh();
}

it('shows the training follow-up of an admitted trainee', function () {
    $c = fuAdmittedTrainee();

    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->has('follow_up.seances', 2)
        ->where('follow_up.seances.0.date', '2027-01-04')
        ->where('follow_up.seances.0.attendance.status', 'present')
        ->where('follow_up.seances.1.past', false)
        ->where('follow_up.hours.realised_minutes', 180)
        ->has('follow_up.signatures'));
});

it('sends only the planning, or nothing, by the rights on the sessions', function () {
    $c = fuAdmittedTrainee();

    $planner = adUser([Permissions::CANDIDATURES_VIEW => null, SessionsPermissions::SESSIONS_VIEW => null]);
    $this->actingAs($planner)->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->has('follow_up.seances', 2)->missing('follow_up.hours')->missing('follow_up.signatures')
        ->missing('follow_up.seances.0.attendance'));

    $reader = adUser([Permissions::CANDIDATURES_VIEW => null]);
    $this->actingAs($reader)->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p->where('follow_up', null));
});

it('sends no follow-up without a dossier', function () {
    $c = adCandidature();

    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p->where('follow_up', null));
});
