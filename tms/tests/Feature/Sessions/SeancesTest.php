<?php

use App\Modules\Catalogue\Training;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Events\SeanceChanged;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Resources\Rooms;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Trainers\Trainers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
});

// ---------------------------------------------------------------- CRUD

it('creates a séance through the form, its duration computed in whole minutes', function () {
    $session = plSession();
    $module = plModule($session);
    $trainer = plTrainer();
    $room = plRoom();

    $this->actingAs(plManager())->post("/sessions/{$session->id}/seances", [
        'session_module_id' => $module->id, 'date' => '2026-09-02', 'starts_at' => '09:00', 'ends_at' => '17:00', 'break_minutes' => '60',
        'trainer_id' => $trainer->id, 'room_id' => $room->id, 'place_text' => '', 'note' => 'Apporter les tenues',
    ])->assertRedirect("/sessions/{$session->id}")->assertSessionHasNoErrors()->assertSessionHas('status', 'Séance ajoutée.');

    $seance = Seance::query()->sole();
    expect($seance->training_session_id)->toBe($session->id)
        ->and($seance->date->toDateString())->toBe('2026-09-02')
        ->and($seance->start())->toBe('09:00')
        ->and($seance->end())->toBe('17:00')
        ->and($seance->break_minutes)->toBe(60)
        ->and($seance->duration_minutes)->toBe(420)
        ->and($seance->status)->toBe('planned')
        ->and($seance->trainer_id)->toBe($trainer->id)
        ->and($seance->room_id)->toBe($room->id)
        ->and($seance->note)->toBe('Apporter les tenues');
});

it('validates a séance in French', function () {
    $session = plSession();
    $module = plModule($session);
    $manager = plManager();
    $url = "/sessions/{$session->id}/seances";
    $base = ['session_module_id' => $module->id, 'date' => '2026-09-02', 'starts_at' => '09:00', 'ends_at' => '12:00', 'break_minutes' => 0];

    $this->actingAs($manager)->post($url, ['session_module_id' => $module->id])->assertSessionHasErrors([
        'date' => 'Indiquez la date de la séance.',
        'starts_at' => 'Indiquez l\'heure de début.',
        'ends_at' => 'Indiquez l\'heure de fin.',
    ]);
    $this->actingAs($manager)->post($url, [...$base, 'ends_at' => '08:00'])->assertSessionHasErrors(['ends_at' => 'L\'heure de fin doit suivre l\'heure de début.']);
    $this->actingAs($manager)->post($url, [...$base, 'break_minutes' => 180])->assertSessionHasErrors(['break_minutes' => 'La pause doit être plus courte que la séance.']);
    $this->actingAs($manager)->post($url, [...$base, 'starts_at' => '9h'])->assertSessionHasErrors(['starts_at' => 'Heure invalide (ex. 09:00).']);
    $this->actingAs($manager)->post($url, [...$base, 'date' => '2026-10-02'])->assertSessionHasErrors(['date' => 'La séance doit rester dans les dates de la session (du 01/09/2026 au 30/09/2026).']);
    $this->actingAs($manager)->post($url, [...$base, 'place_text' => str_repeat('a', 301)])->assertSessionHasErrors(['place_text' => 'Le lieu ne doit pas dépasser 300 caractères.']);
    $this->actingAs($manager)->post($url, [...$base, 'note' => str_repeat('a', 1001)])->assertSessionHasErrors(['note' => 'La note ne doit pas dépasser 1 000 caractères.']);
    $this->actingAs($manager)->post($url, [...$base, 'session_module_id' => '0190d6c5-0000-7000-8000-000000000000'])->assertSessionHasErrors(['session_module_id' => 'Module introuvable.']);
    plSeance($module, '2026-09-02', '09:00', '10:00');
    $this->actingAs($manager)->post($url, $base)->assertSessionHasErrors(['starts_at' => 'Une séance de ce module commence déjà à cette heure ce jour-là.']);
    expect(Seance::query()->count())->toBe(1);

    // A module dated as an interval has no séance (409).
    $interval = SessionModule::query()->create(['training_session_id' => $session->id, 'position' => 2, 'title' => 'Stage pratique', 'planned_minutes' => 60, 'dating_mode' => 'interval']);
    $this->actingAs($manager)->post($url, [...$base, 'session_module_id' => $interval->id])->assertStatus(409);
});

it('reschedules a séance and tells the attendance (SeanceChanged after commit)', function () {
    Event::fake([SeanceChanged::class]);
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02', '09:00', '12:00');

    $this->actingAs(plManager())->put("/sessions/{$session->id}/seances/{$seance->id}", [
        'session_module_id' => $module->id, 'date' => '2026-09-03', 'starts_at' => '13:30', 'ends_at' => '17:00', 'break_minutes' => 15,
    ])->assertRedirect("/sessions/{$session->id}")->assertSessionHasNoErrors()->assertSessionHas('status', 'Séance enregistrée.');

    $seance->refresh();
    expect($seance->date->toDateString())->toBe('2026-09-03')->and($seance->start())->toBe('13:30')->and($seance->duration_minutes)->toBe(195);
    Event::assertDispatched(SeanceChanged::class, fn (SeanceChanged $e) => $e->seanceId === $seance->id && $e->kind === SeanceChanged::RESCHEDULED);

    // Changing the trainer only does not touch the schedule.
    Event::fake([SeanceChanged::class]);
    app(Seances::class)->reschedule($seance, ['note' => 'Salle B']);
    Event::assertNotDispatched(SeanceChanged::class);
});

it('moves a séance to another module of the session', function () {
    $session = plSession();
    $module = plModule($session);
    $other = SessionModule::query()->create(['training_session_id' => $session->id, 'position' => 2, 'title' => 'Pratique', 'planned_minutes' => 600, 'dating_mode' => 'seances']);
    $seance = plSeance($module, '2026-09-02');

    app(Seances::class)->reschedule($seance, ['session_module_id' => $other->id]);

    expect($seance->refresh()->session_module_id)->toBe($other->id)
        ->and($module->hasActivity())->toBeFalse()->and($other->hasActivity())->toBeTrue();
});

it('cancels a séance with a reason, kept visible, and deletes one without attendance', function () {
    Event::fake([SeanceChanged::class]);
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02');
    $manager = plManager();

    $this->actingAs($manager)->post("/sessions/{$session->id}/seances/{$seance->id}/cancel", ['reason' => ''])
        ->assertSessionHasErrors(['reason' => 'Indiquez le motif de l\'annulation.']);
    $this->actingAs($manager)->post("/sessions/{$session->id}/seances/{$seance->id}/cancel", ['reason' => 'Formateur absent'])
        ->assertRedirect()->assertSessionHas('status', 'Séance annulée.');
    $seance->refresh();
    expect($seance->status)->toBe('cancelled')->and($seance->cancel_reason)->toBe('Formateur absent');
    Event::assertDispatched(SeanceChanged::class, fn (SeanceChanged $e) => $e->kind === SeanceChanged::CANCELLED);
    $this->actingAs($manager)->post("/sessions/{$session->id}/seances/{$seance->id}/cancel", ['reason' => 'Encore'])->assertStatus(409);

    $other = plSeance($module, '2026-09-03');
    $this->actingAs($manager)->delete("/sessions/{$session->id}/seances/{$other->id}")->assertRedirect()->assertSessionHas('status', 'Séance supprimée.');
    expect(Seance::query()->find($other->id))->toBeNull()->and(Seance::withTrashed()->find($other->id))->not->toBeNull();
});

it('refuses to delete a séance with attendance (the hook of task 4)', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    Seances::registerAttendance('test', fn (string $id) => $id === $seance->id);

    $this->actingAs(plManager())->delete("/sessions/{$session->id}/seances/{$seance->id}")
        ->assertStatus(409);
    expect(Seance::query()->find($seance->id))->not->toBeNull();
})->after(fn () => Seances::registerAttendance('test', fn () => false));

it('logs a séance without its note nor the reason of a cancellation in clear', function () {
    $seance = plSeance(plModule(plSession()), '2026-09-02', extra: ['note' => 'Allergie de Léa']);
    app(Seances::class)->cancel($seance, 'Maladie du formateur');

    $logs = DB::table('audit_logs')->where('auditable_type', 'seance')->get();
    expect($logs->count())->toBeGreaterThanOrEqual(2)
        ->and($logs->pluck('new_values')->implode(' '))->not->toContain('Allergie')->not->toContain('Maladie');
});

// ---------------------------------------------------------------- usage

it('gives the module activity once it has séances: neither deleted nor switched', function () {
    $session = plSession();
    $module = plModule($session);
    expect($module->hasActivity())->toBeFalse();
    plSeance($module, '2026-09-02');

    expect($module->hasActivity())->toBeTrue();
    $this->actingAs(plManager())->delete("/sessions/{$session->id}/modules/{$module->id}")->assertStatus(409);
    $this->actingAs(plManager())->post("/sessions/{$session->id}/modules/{$module->id}/mode", ['dating_mode' => 'interval'])->assertStatus(409);
});

it('keeps a room and a trainer used by a séance from being deleted', function () {
    SessionsServiceProvider::registerPlanningUsage();
    $trainer = plTrainer();
    $room = plRoom();
    plSeance(plModule(plSession()), '2026-09-02', extra: ['trainer_id' => $trainer->id, 'room_id' => $room->id]);

    expect(Rooms::inUse($room->id))->toBeTrue()
        ->and(Trainers::inUse($trainer->id))->toBeTrue()
        ->and(Rooms::inUse(plRoom('Salle B')->id))->toBeFalse();
});

// ---------------------------------------------------------------- closures

it('asks the closure guard before writing a séance (wired by task 4)', function () {
    $session = plSession();
    $module = plModule($session);
    $calls = [];
    Seances::guardMonthsWith(function (TrainingSession $s, string $date) use (&$calls): void {
        $calls[] = $date;
    });

    $seance = plSeance($module, '2026-09-02');
    app(Seances::class)->reschedule($seance, ['date' => '2026-09-10']);
    app(Seances::class)->cancel($seance->refresh(), 'Grève');
    app(Seances::class)->delete($seance->refresh());

    expect($calls)->toBe(['2026-09-02', '2026-09-02', '2026-09-10', '2026-09-10', '2026-09-10']);
})->after(fn () => Seances::guardMonthsWith(null));

// ---------------------------------------------------------------- rights

it('needs sessions.seances.manage and its scope on the session to plan', function () {
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02');
    $input = ['session_module_id' => $module->id, 'date' => '2026-09-03', 'starts_at' => '09:00', 'ends_at' => '12:00'];

    $viewer = userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW]);
    $this->actingAs($viewer)->post("/sessions/{$session->id}/seances", $input)->assertForbidden();
    $this->actingAs($viewer)->delete("/sessions/{$session->id}/seances/{$seance->id}")->assertForbidden();

    // « Ses fiches » on séances: a session of somebody else is out of reach.
    $own = l2User([Permissions::SESSIONS_VIEW => null, Permissions::SEANCES_VIEW => null, Permissions::SEANCES_MANAGE => AccessScope::Own]);
    $this->actingAs($own)->post("/sessions/{$session->id}/seances", $input)->assertForbidden();
    $session->forceFill(['responsible_user_id' => $own->id])->save();
    $this->actingAs($own)->post("/sessions/{$session->id}/seances", $input)->assertRedirect()->assertSessionHasNoErrors();

    // A séance of another session through this session's URL: 404.
    $other = plSeance(plModule(plSession()), '2026-09-02');
    $this->actingAs(plManager())->delete("/sessions/{$session->id}/seances/{$other->id}")->assertNotFound();
});

it('keeps the séances of another organisme out of reach (RLS)', function () {
    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, function () use ($other) {
        plSetUp($other);

        return plSeance(plModule(plSession()), '2026-09-02');
    });
    inTenant();
    $session = plSession();

    expect(Seance::query()->find($foreign->id))->toBeNull();
    $this->actingAs(plManager())->delete("/sessions/{$session->id}/seances/{$foreign->id}")->assertNotFound();
});

it('shows a trainer his séances only, and the sessions holding them (« Ses séances »)', function () {
    $formateur = userWithRole(Role::Formateur);
    $mine = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    expect($mine)->not->toBeNull();
    assert($mine !== null);
    $other = plTrainer('Bernard');
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02', extra: ['trainer_id' => $mine->id]);
    plSeance($module, '2026-09-03', extra: ['trainer_id' => $other->id]);
    $elsewhere = plSession();
    plSeance(plModule($elsewhere), '2026-09-02', extra: ['trainer_id' => $other->id]);

    expect(SessionsVisibility::seances(Seance::query(), $formateur)->pluck('id')->all())->toBe([$seance->id])
        ->and(SessionsVisibility::sessions(TrainingSession::query(), $formateur, Permissions::SEANCES_VIEW)->pluck('id')->all())->toBe([$session->id])
        ->and(SessionsVisibility::allows($formateur, Permissions::SEANCES_VIEW, $session))->toBeTrue()
        ->and(SessionsVisibility::allows($formateur, Permissions::SEANCES_VIEW, $elsewhere))->toBeFalse()
        // Never the sessions themselves: no sessions.sessions.view.
        ->and(SessionsVisibility::sessions(TrainingSession::query(), $formateur)->count())->toBe(0);
    $this->actingAs($formateur)->get("/sessions/{$session->id}")->assertNotFound();

    // « Tout l'organisme »: every séance.
    expect(SessionsVisibility::seances(Seance::query(), plManager())->count())->toBe(3);
});

// ---------------------------------------------------------------- planning tab

it('sends the planning tab: séances with alerts, totals per module, placements, holidays and closures', function () {
    $session = plSession('2026-07-01', '2026-07-31');
    $module = plModule($session);
    ClosurePeriod::query()->create(['starts_on' => '2026-07-27', 'ends_on' => '2026-08-07', 'label' => 'Congés d\'été']);
    $trainer = plTrainer();
    plSeance($module, '2026-07-14', '09:00', '12:00', ['trainer_id' => $trainer->id]);
    plSeance($module, '2026-07-15', '09:00', '17:00', ['break_minutes' => 60]);

    $this->actingAs(plManager())->get("/sessions/{$session->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('planning.seances', 2)
        ->where('planning.seances.0.date', '2026-07-14')
        ->where('planning.seances.0.start', '09:00')
        ->where('planning.seances.0.trainer.label', 'Paul Martin')
        ->where('planning.seances.0.alerts.0.type', 'holiday')
        ->where('planning.seances.1.duration', '7 h')
        ->where('planning.modules.0.scheduled_minutes', 600)
        ->where('planning.modules.0.scheduled', '10 h')
        ->where('planning.calendar.holidays', ['2026-07-14' => 'Fête nationale'])
        ->where('planning.calendar.closures.0.label', 'Congés d\'été')
        ->where('planning.can_seances', true)
        ->has('planning.options.trainers', 1)
        ->has('planning.seance_modules', 1));
});

it('warns of a module without planned hours, shown « à définir » and counted 0', function () {
    $session = plSession();
    SessionModule::query()->create(['training_session_id' => $session->id, 'position' => 2, 'title' => 'Atelier libre', 'planned_minutes' => null, 'dating_mode' => 'seances']);

    $this->actingAs(plManager())->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('planning.warnings', fn ($w) => in_array('Le module « Atelier libre » n\'a pas d\'heures prévues.', collect($w)->all(), true))
        ->where('planning.modules.1.planned', 'à définir')
        ->where('planning.total', '21 h'));
});

it('warns of a module planned above or below its hours', function () {
    $session = plSession();
    $module = plModule($session);
    plSeance($module, '2026-09-02', '09:00', '12:00');

    $this->actingAs(plManager())->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('planning.warnings', fn ($w) => in_array('Le module « HACCP 3 jours » : 3 h planifiées pour 21 h prévues.', collect($w)->all(), true)));
});

// ---------------------------------------------------------------- catalogue modules

it('takes the modules of the training again after a preview, refused once a module has séances', function () {
    $training = Training::query()->create(['title' => 'Commis de cuisine', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '21.00']);
    $session = l2Session($training, ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
    expect(SessionModule::query()->where('training_session_id', $session->id)->count())->toBe(1);
    $training->modules()->create(['position' => 0, 'title' => 'Hygiène', 'duration_hours' => '7.00']);
    $training->modules()->create(['position' => 1, 'title' => 'Cuisson', 'duration_hours' => '14.00']);
    $manager = plManager();

    $this->actingAs($manager)->getJson("/sessions/{$session->id}/modules/catalogue")->assertOk()
        ->assertJsonPath('modules.0.title', 'Hygiène')->assertJsonPath('modules.0.planned', '7 h')
        ->assertJsonPath('modules.1.planned', '14 h')->assertJsonPath('total', '21 h')
        ->assertJsonPath('current.0.title', 'Commis de cuisine')->assertJsonPath('locked', false);
    expect(SessionModule::query()->where('training_session_id', $session->id)->count())->toBe(1);

    $this->actingAs($manager)->post("/sessions/{$session->id}/modules/catalogue")->assertRedirect()->assertSessionHas('status', 'Modules de la formation repris.');
    expect(SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->pluck('title')->all())->toBe(['Hygiène', 'Cuisson']);

    plSeance(plModule($session->refresh()), '2026-09-02');
    $this->actingAs($manager)->getJson("/sessions/{$session->id}/modules/catalogue")->assertJsonPath('locked', true);
    $this->actingAs($manager)->post("/sessions/{$session->id}/modules/catalogue")->assertStatus(409);
    $this->actingAs(userWithPermissions([Permissions::SESSIONS_VIEW]))->post("/sessions/{$session->id}/modules/catalogue")->assertForbidden();
});

// ---------------------------------------------------------------- session hours

it('takes the hours of a session from the modules of its training, refusing other typed hours', function () {
    $training = Training::query()->create(['title' => 'Commis de cuisine', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '21.00']);
    $training->modules()->create(['position' => 0, 'title' => 'Hygiène', 'duration_hours' => '7.00']);
    $training->modules()->create(['position' => 1, 'title' => 'Cuisson', 'duration_hours' => '3.50']);

    expect(fn () => l2Session($training, ['planned_hours' => '30']))->toThrow(ValidationException::class,
        'Cette formation a des modules : les heures de la session sont leur somme (10 h 30). Laissez ce champ vide.');
    expect(l2Session($training, ['planned_hours' => ''])->planned_hours)->toBe('10.50')
        ->and(l2Session($training, ['planned_hours' => '10,5'])->planned_hours)->toBe('10.50');

    $this->actingAs(plManager())->get('/sessions/create')->assertInertia(fn (Assert $page) => $page
        ->where('options.training', fn ($options) => collect($options)->firstWhere('value', $training->id)['modules_hours'] === '10 h 30'));
});

// ---------------------------------------------------------------- sessions calendar

it('shows a session on the month calendar on its séance days, or on working days only', function () {
    ClosurePeriod::query()->create(['starts_on' => '2026-11-12', 'ends_on' => '2026-11-13', 'label' => 'Pont']);
    // An interval session over two weeks with a holiday (11th), a closure and two weekends.
    $interval = plSession('2026-11-09', '2026-11-20');
    // A session with séances on two days only.
    $dated = plSession('2026-11-02', '2026-11-27');
    $module = plModule($dated);
    plSeance($module, '2026-11-03');
    plSeance($module, '2026-11-03', '13:30', '17:00');
    plSeance($module, '2026-11-17');

    $this->actingAs(plManager())->get('/sessions/calendar?month=2026-11')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sessions', function ($rows) use ($interval, $dated): bool {
            $rows = collect($rows)->keyBy('id');

            return $rows[$interval->id]['days'] === ['2026-11-09', '2026-11-10', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20']
                && $rows[$dated->id]['days'] === ['2026-11-03', '2026-11-17'];
        })
        ->where('holidays', ['2026-11-01' => 'Toussaint', '2026-11-11' => 'Armistice 1918'])
        ->where('closures.0.label', 'Pont'));
});
