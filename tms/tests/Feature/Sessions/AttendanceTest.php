<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\History\SessionsHistory;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Events\SeanceChanged;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
});

// ---------------------------------------------------------------- statuses and minutes

it('gives a present trainee the duration of the séance, absent and excused 0', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    $anne = atEnrol($session, 'Roux');
    $attendances = app(Attendances::class);

    $present = $attendances->record($seance, $lea->id, 'present', null, null);
    // A minutes value typed with « present » is ignored: the duration rules.
    $absent = $attendances->record($seance, $marc->id, 'absent', 120, 'Malade');
    $excused = $attendances->record($seance, $anne->id, 'excused', null, 'Convocation');

    expect($present->minutes)->toBe(420)->and($present->status)->toBe('present')->and($present->needs_review)->toBeFalse()
        ->and($absent->minutes)->toBe(0)->and($absent->reason)->toBe('Malade')
        ->and($excused->minutes)->toBe(0)
        ->and(Attendance::query()->count())->toBe(3);

    // Recorded again: the same row changed (one per séance and trainee).
    $attendances->record($seance, $lea->id, 'absent', null, null);
    expect(Attendance::query()->count())->toBe(3)->and(Attendance::query()->find($present->id)?->minutes)->toBe(0);
});

it('requires 0 < minutes < duration for a partial attendance', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:30');
    $lea = atEnrol($session);
    $attendances = app(Attendances::class);
    $error = function (?int $minutes) use ($attendances, $seance, $lea): ?string {
        try {
            $attendances->record($seance, $lea->id, 'partial', $minutes, null);
        } catch (ValidationException $e) {
            return $e->errors()['minutes'][0] ?? null;
        }

        return null;
    };

    expect($error(null))->toBe('Indiquez les minutes de présence.')
        ->and($error(0))->toBe('Une présence partielle dure entre 1 minute et la durée de la séance (3 h 30) exclue.')
        ->and($error(210))->toBe('Une présence partielle dure entre 1 minute et la durée de la séance (3 h 30) exclue.')
        ->and($error(209))->toBeNull()
        ->and(Attendance::query()->sole()->minutes)->toBe(209);

    expect(fn () => $attendances->record($seance, $lea->id, 'late', null, null))->toThrow(ValidationException::class);
    expect(fn () => $attendances->record($seance, $lea->id, 'absent', null, str_repeat('x', 301)))->toThrow(ValidationException::class);
});

it('marks every expected trainee present at once, leaving those already entered', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    atEnrol($session, 'Roux');
    $attendances = app(Attendances::class);
    $attendances->record($seance, $marc->id, 'absent', null, null);

    expect($attendances->allPresent($seance))->toBe(2)
        ->and(Attendance::query()->where('enrolment_id', $marc->id)->sole()->status)->toBe('absent')
        ->and(Attendance::query()->where('enrolment_id', $lea->id)->sole()->minutes)->toBe(180)
        ->and($attendances->allPresent($seance))->toBe(0);
});

// ---------------------------------------------------------------- late entries and abandons

it('takes no attendance before the entry day of a late trainee, nor after an abandon', function () {
    $session = plSession();
    $module = plModule($session);
    $tenth = plSeance($module, '2026-09-10');
    $sixteenth = plSeance($module, '2026-09-16');
    $twentyFirst = plSeance($module, '2026-09-21');
    $late = atEnrol($session, 'Tardif', ['entered_on' => '2026-09-15']);
    $leaving = atEnrol($session, 'Parti');
    app(Enrolments::class)->abandon($leaving, CarbonImmutable::parse('2026-09-20'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);
    $attendances = app(Attendances::class);

    expect(fn () => $attendances->record($tenth, $late->id, 'present', null, null))
        ->toThrow(ValidationException::class, 'Ce stagiaire est entré en formation le 15/09/2026 : pas de présence avant cette date.');
    expect($attendances->record($sixteenth, $late->id, 'present', null, null)->minutes)->toBe(210);

    expect($attendances->record($sixteenth, $leaving->id, 'present', null, null)->minutes)->toBe(210);
    expect(fn () => $attendances->record($twentyFirst, $leaving->id, 'present', null, null))
        ->toThrow(ValidationException::class, 'Ce stagiaire a quitté la formation le 20/09/2026 : pas de présence après cette date.');

    // « Tous présents » skips them too.
    expect($attendances->allPresent($tenth))->toBe(1)
        ->and(Attendance::query()->where('seance_id', $tenth->id)->pluck('enrolment_id')->all())->toBe([$leaving->id]);
    expect($attendances->allPresent($twentyFirst))->toBe(1)
        ->and(Attendance::query()->where('seance_id', $twentyFirst->id)->pluck('enrolment_id')->all())->toBe([$late->id]);
});

it('takes no attendance of a waitlisted or cancelled trainee, nor of another session', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $cancelled = atEnrol($session, 'Annulé');
    app(Enrolments::class)->cancel($cancelled);
    $other = atEnrol(plSession(), 'Ailleurs');
    $attendances = app(Attendances::class);

    expect(fn () => $attendances->record($seance, $cancelled->id, 'present', null, null))
        ->toThrow(ValidationException::class, 'Seul un stagiaire inscrit, confirmé ou sorti en cours de formation a des présences.');
    expect(fn () => $attendances->record($seance, $other->id, 'present', null, null))
        ->toThrow(ValidationException::class, 'Stagiaire introuvable sur cette session.');
    expect($attendances->allPresent($seance))->toBe(0);
});

it('refuses attendance on a cancelled séance (409)', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    app(Seances::class)->cancel($seance, 'Grève');

    $this->actingAs(atManager())->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $lea->id, 'status' => 'present'])
        ->assertStatus(409)->assertJson(['message' => 'Cette séance est annulée : ses présences ne se saisissent plus.']);
});

// ---------------------------------------------------------------- séance changed (Review Focus 2)

it('puts the attendance « à revoir » when the séance hours change: present recomputed, partial capped', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $present = atEnrol($session, 'Present');
    $partial = atEnrol($session, 'Partiel');
    $short = atEnrol($session, 'Court');
    $absent = atEnrol($session, 'Absent');
    $attendances = app(Attendances::class);
    $attendances->record($seance, $present->id, 'present', null, null);
    $attendances->record($seance, $partial->id, 'partial', 300, null);
    $attendances->record($seance, $short->id, 'partial', 60, null);
    $attendances->record($seance, $absent->id, 'absent', null, null);

    // 420 min → 09:00–12:00, 180 min.
    app(Seances::class)->reschedule($seance, ['ends_at' => '12:00', 'break_minutes' => 0]);

    $rows = Attendance::query()->get()->keyBy('enrolment_id');
    // A partial above the new duration is capped and becomes present (partial stays < duration).
    expect($rows[$present->id]->minutes)->toBe(180)->and($rows[$present->id]->status)->toBe('present')
        ->and($rows[$partial->id]->minutes)->toBe(180)->and($rows[$partial->id]->status)->toBe('present')
        ->and($rows[$short->id]->minutes)->toBe(60)
        ->and($rows[$absent->id]->minutes)->toBe(0)
        ->and($rows->every(fn (Attendance $a) => $a->needs_review))->toBeTrue();

    // Entered again: reviewed.
    $attendances->record($seance, $partial->id, 'partial', 150, null);
    expect(Attendance::query()->where('enrolment_id', $partial->id)->sole()->needs_review)->toBeFalse();

    // The note or the trainer only: nothing to review.
    Attendance::query()->update(['needs_review' => false]);
    app(Seances::class)->reschedule($seance->refresh(), ['note' => 'Salle B']);
    expect(Attendance::query()->where('needs_review', true)->count())->toBe(0);
});

it('sets the attendance of a cancelled séance to 0 minute, kept and journaled', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session);
    $attendance = app(Attendances::class)->record($seance, $lea->id, 'present', null, null);

    app(Seances::class)->cancel($seance, 'Formateur malade');

    $attendance->refresh();
    expect($attendance->minutes)->toBe(0)->and($attendance->status)->toBe('present')->and($attendance->needs_review)->toBeTrue();
    $log = DB::table('audit_logs')->where('auditable_type', 'attendance')->where('auditable_id', $attendance->id)->where('event', 'updated')->sole();
    expect(json_decode((string) $log->old_values, true))->toBe(['minutes' => 180, 'needs_review' => false])
        ->and(json_decode((string) $log->new_values, true))->toBe(['minutes' => 0, 'needs_review' => true]);
});

it('refuses to delete a séance with attendance, even absent (Review Focus 2)', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    app(Attendances::class)->record($seance, $lea->id, 'absent', null, null);

    $this->actingAs(plManager())->delete("/sessions/{$session->id}/seances/{$seance->id}")
        ->assertStatus(409);
    expect(Seance::query()->find($seance->id))->not->toBeNull();

    // Cleared: it can go.
    app(Attendances::class)->clear($seance, $lea->id);
    $this->actingAs(plManager())->delete("/sessions/{$session->id}/seances/{$seance->id}")->assertRedirect();
    expect(Seance::query()->find($seance->id))->toBeNull();
});

it('gives the module activity once attendance is entered (and SessionModules::rows agrees)', function () {
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02');
    $lea = atEnrol($session);
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);

    expect($module->hasActivity())->toBeTrue()
        ->and(SessionModules::rows($session)[0]['has_activity'])->toBeTrue();
});

// ---------------------------------------------------------------- who enters (Review Focus 4)

it('lets the trainer of the séance enter its attendance, never another trainer (404)', function () {
    $formateur = userWithRole(Role::Formateur);
    $mine = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    assert($mine !== null);
    $other = userWithRole(Role::Formateur);
    Trainers::forUser($other) ?? Trainers::ensureForUser($other);
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00', ['trainer_id' => $mine->id]);
    $lea = atEnrol($session, 'Durand', ['adaptation_needs' => 'Fauteuil roulant', 'notes' => 'Payé par la mairie']);

    $this->actingAs($formateur)->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $lea->id, 'status' => 'present'])
        ->assertOk()->assertJsonPath('rows.0.minutes', 180)->assertJsonPath('rows.0.status', 'present');
    $this->actingAs($formateur)->postJson("/seances/{$seance->id}/attendance/all-present")->assertOk()->assertJsonPath('count', 0);

    $grid = $this->actingAs($formateur)->getJson("/seances/{$seance->id}/attendance")->assertOk();
    $grid->assertJsonPath('rows.0.name', 'Léa Durand')->assertJsonPath('can_edit', true);
    $body = (string) $grid->getContent();
    expect($body)->not->toContain('Fauteuil')->not->toContain('mairie')->not->toContain('adaptation')->not->toContain('notes')
        ->not->toContain('price')->not->toContain('funding');

    $this->actingAs($other)->getJson("/seances/{$seance->id}/attendance")->assertNotFound();
    $this->actingAs($other)->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $lea->id, 'status' => 'absent'])->assertNotFound();
    expect(Attendance::query()->sole()->status)->toBe('present');
});

it('opens to a trainer, from the « Suivi » of the session, the attendance of his own séances only', function () {
    $formateur = userWithRole(Role::Formateur);
    $mine = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    assert($mine !== null);
    // His role also opens the sessions he is responsible for (as set by an administrator).
    $role = $formateur->roles()->firstOrFail();
    $role->givePermissionTo(Permissions::SESSIONS_VIEW);
    DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => Permissions::SESSIONS_VIEW, 'scope' => 'own']);
    Scopes::forget();
    $colleague = plTrainer('Bernard');
    $session = plSession('2026-09-01', '2026-09-30', ['responsible_user_id' => $formateur->id]);
    $module = plModule($session);
    $his = plSeance($module, '2026-09-02', '09:00', '12:00', ['trainer_id' => $mine->id]);
    $theirs = plSeance($module, '2026-09-03', '09:00', '12:00', ['trainer_id' => $colleague->id]);
    atEnrol($session);

    $rows = collect($this->actingAs($formateur)->getJson("/sessions/{$session->id}/attendance?month=2026-09")->assertOk()
        ->assertJsonPath('can.manage', false)->json('seances'))->keyBy('id');
    expect($rows[$his->id]['can_record'])->toBeTrue()
        ->and($rows[$theirs->id]['can_record'])->toBeFalse();

    // A manager may open every séance.
    $manager = l2User([Permissions::SESSIONS_VIEW => null, Permissions::SEANCES_VIEW => null, Permissions::ATTENDANCE_MANAGE => null]);
    $all = collect($this->actingAs($manager)->getJson("/sessions/{$session->id}/attendance?month=2026-09")->json('seances'));
    expect($all->pluck('can_record')->unique()->all())->toBe([true]);
});

it('lets a manager enter the attendance of the sessions of his scope only', function () {
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    $outside = l2User([Permissions::SEANCES_VIEW => AccessScope::Das, Permissions::ATTENDANCE_MANAGE => AccessScope::Das]);
    $inside = l2User([Permissions::SEANCES_VIEW => AccessScope::Das, Permissions::ATTENDANCE_MANAGE => AccessScope::Das]);
    followDas($inside, $das);
    $viewer = l2User([Permissions::SEANCES_VIEW => null]);

    $this->actingAs($outside)->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $lea->id, 'status' => 'present'])->assertNotFound();
    $this->actingAs($viewer)->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $lea->id, 'status' => 'present'])->assertForbidden();
    $this->actingAs($inside)->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $lea->id, 'status' => 'present'])->assertOk();
    // A séance without trainer: managers only (a trainer never reaches it).
    expect(Attendance::query()->count())->toBe(1);
});

it('lists the expected trainees with their attendance, clears one and validates in French', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    $manager = atManager();

    $this->actingAs($manager)->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $marc->id, 'status' => 'partial', 'minutes' => 500])
        ->assertStatus(422)->assertJsonValidationErrors(['minutes' => 'Une présence partielle dure entre 1 minute et la durée de la séance (3 h) exclue.']);
    $this->actingAs($manager)->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $marc->id, 'status' => 'partial', 'minutes' => 90, 'reason' => 'Rendez-vous médical'])
        ->assertOk();

    $this->actingAs($manager)->getJson("/seances/{$seance->id}/attendance")->assertOk()
        ->assertJsonPath('seance.duration_minutes', 180)
        ->assertJsonPath('seance.closed', false)
        ->assertJsonPath('rows.0.enrolment_id', $lea->id)
        ->assertJsonPath('rows.0.status', null)
        ->assertJsonPath('rows.1.status', 'partial')
        ->assertJsonPath('rows.1.minutes', 90)
        ->assertJsonPath('rows.1.reason', 'Rendez-vous médical')
        ->assertJsonPath('rows.1.eligible', true);

    $this->actingAs($manager)->deleteJson("/seances/{$seance->id}/attendance/{$marc->id}")->assertOk()->assertJsonPath('rows.1.status', null);
    expect(Attendance::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- traceability

it('journals the attendance with ids and minutes only, never a name nor a reason', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $attendances = app(Attendances::class);
    $attendances->record($seance, $lea->id, 'absent', null, 'Hospitalisée');
    $attendances->record($seance, $lea->id, 'partial', 60, 'Rendez-vous chez le psychiatre');
    $attendances->clear($seance, $lea->id);

    $logs = DB::table('audit_logs')->where('auditable_type', 'attendance')->orderBy('created_at')->get();
    expect($logs->pluck('event')->all())->toBe(['created', 'updated', 'deleted']);
    $all = $logs->map(fn ($l) => $l->old_values.' '.$l->new_values)->implode(' ');
    expect($all)->not->toContain('Hospitalisée')->not->toContain('psychiatre')->not->toContain('Durand')->not->toContain('Léa')
        ->toContain($lea->id)->toContain($seance->id);
    expect(json_decode((string) $logs[1]->new_values, true))->toMatchArray(['status' => 'partial', 'minutes' => 60, 'reason' => '[donnée personnelle]']);
});

it('shows the attendance in the history of the dossier', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
    app(Attendances::class)->record($seance, $lea->id, 'partial', 90, 'Retard');

    $titles = collect(SessionsHistory::dossierPage($lea->dossier()->firstOrFail()))->pluck('title')->all();
    expect($titles)->toContain('Léa Durand - Présence du 02/09/2026 : présent (3 h)')
        ->toContain('Léa Durand - Présence du 02/09/2026 modifiée : partiel (1 h 30)')
        ->and(implode(' ', $titles))->not->toContain('Retard');
});

it('clears the reasons of her attendance when a trainee is anonymised, minutes kept', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    app(Attendances::class)->record($seance, $lea->id, 'partial', 60, 'Rendez-vous médical');
    app(Attendances::class)->record($seance, $marc->id, 'absent', null, 'Panne');

    app(Anonymiser::class)->anonymise(Person::query()->findOrFail($lea->person_id), 'request');

    $rows = Attendance::query()->get()->keyBy('enrolment_id');
    expect($rows[$lea->id]->reason)->toBeNull()->and($rows[$lea->id]->minutes)->toBe(60)
        ->and($rows[$marc->id]->reason)->toBe('Panne');
});

it('reviews the attendance inside the séance transaction: a failing review rolls the change back', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session);
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
    Seances::onScheduleChange('test', function (): void {
        throw new RuntimeException('Échec de la revue');
    });

    expect(fn () => app(Seances::class)->reschedule($seance, ['ends_at' => '11:00']))->toThrow(RuntimeException::class);
    expect($seance->refresh()->end())->toBe('12:00')->and(Attendance::query()->sole()->minutes)->toBe(180);
    expect(fn () => app(Seances::class)->cancel($seance, 'Grève'))->toThrow(RuntimeException::class);
    expect($seance->refresh()->status)->toBe('planned')->and(Attendance::query()->sole()->minutes)->toBe(180);
})->after(fn () => Seances::onScheduleChange('test', null));

it('reviews the attendance even when SeanceChanged is faked (no listener needed)', function () {
    Event::fake([SeanceChanged::class]);
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session);
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);

    app(Seances::class)->reschedule($seance, ['ends_at' => '11:00']);
    expect(Attendance::query()->sole()->minutes)->toBe(120);
    app(Seances::class)->cancel($seance->refresh(), 'Grève');
    expect(Attendance::query()->sole()->minutes)->toBe(0);
});

it('takes the session lock before the module and the séance when writing a séance', function () {
    $session = plSession();
    $module = plModule($session);
    DB::enableQueryLog();
    $seance = plSeance($module, '2026-09-02');
    app(Seances::class)->reschedule($seance, ['ends_at' => '11:00']);
    app(Seances::class)->cancel($seance->refresh(), 'Grève');
    $log = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $locks = $log->filter(fn (string $q) => str_contains($q, ' for update') || str_contains($q, ' for share'))
        ->map(fn (string $q) => (str_contains($q, '"training_sessions"') ? 'session' : (str_contains($q, '"session_modules"') ? 'module' : (str_contains($q, '"seances"') ? 'seance' : 'other')))
            .(str_contains($q, ' for share') ? ':share' : ':update'))
        ->values()->all();
    // create: session → module; reschedule: session → modules → séance; cancel: session → séance.
    expect(array_slice($locks, 0, 2))->toBe(['session:share', 'module:update'])
        ->and(array_values(array_filter($locks, fn ($l) => str_starts_with($l, 'session'))))->toBe(['session:share', 'session:share', 'session:share']);
    foreach ([['session:share', 'module:update'], ['session:share', 'seance:update']] as [$first, $then]) {
        expect(array_search($first, $locks, true))->toBeLessThan(array_search($then, $locks, true));
    }
});
