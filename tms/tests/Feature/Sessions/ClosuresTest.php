<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\MonthlyHours;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\GenerationRequest;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\SeanceGenerator;
use App\Modules\Sessions\Planning\Seances;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

const CL_SEPTEMBER = 'Le mois de septembre 2026 est clôturé pour cette session : rouvrez-le pour modifier ses présences, ses heures ou ses séances.';

beforeEach(function () {
    plSetUp();
});

it('closes a Paris month of a session and tells it closed', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $closures = app(Closures::class);

    $closures->close($session, '2026-09');

    expect($closures->isClosed($session, CarbonImmutable::parse('2026-09-30')))->toBeTrue()
        ->and($closures->isClosed($session, CarbonImmutable::parse('2026-09-01')))->toBeTrue()
        ->and($closures->isClosed($session, CarbonImmutable::parse('2026-10-01')))->toBeFalse()
        ->and($closures->isClosed(plSession('2026-09-01', '2026-09-30'), CarbonImmutable::parse('2026-09-10')))->toBeFalse()
        ->and($closures->closedMonths($session))->toBe(['2026-09']);
    expect(fn () => $closures->close($session, '2026-09'))->toThrow(UserFacingHttpException::class, 'Ce mois est déjà clôturé.');
    expect(fn () => $closures->close($session, '2026-12'))->toThrow(ValidationException::class, 'Ce mois est hors des dates de la session.');
});

it('locks attendance, monthly hours and the séances of a closed month (409 in French, Review Focus 3)', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $module = plModule($session);
    $interval = atIntervalModule($session);
    $seance = plSeance($module, '2026-09-02', '09:00', '12:00');
    $empty = plSeance($module, '2026-09-03', '09:00', '12:00');
    $october = plSeance($module, '2026-10-05', '09:00', '12:00');
    $lea = atEnrol($session);
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
    app(MonthlyHours::class)->record($lea->id, $interval, '2026-09', 600, null);
    $manager = atManager();
    $this->actingAs($manager)->postJson("/sessions/{$session->id}/closures", ['month' => '2026-09'])->assertOk();

    $conflict = fn ($response) => $response->assertStatus(409)->assertJson(['message' => CL_SEPTEMBER]);
    // Attendance and « Tous présents ».
    $conflict($this->actingAs($manager)->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $lea->id, 'status' => 'absent']));
    $conflict($this->actingAs($manager)->deleteJson("/seances/{$seance->id}/attendance/{$lea->id}"));
    $conflict($this->actingAs($manager)->postJson("/seances/{$empty->id}/attendance/all-present"));
    // Monthly hours.
    $conflict($this->actingAs($manager)->postJson("/sessions/{$session->id}/monthly-hours", [
        'enrolment_id' => $lea->id, 'session_module_id' => $interval->id, 'month' => '2026-09', 'minutes' => 30,
    ]));
    // Séances: change of hours, delete, create, cancel, move into the month.
    $base = ['session_module_id' => $module->id, 'date' => '2026-09-02', 'starts_at' => '09:00', 'ends_at' => '11:00'];
    $conflict($this->actingAs($manager)->putJson("/sessions/{$session->id}/seances/{$seance->id}", $base));
    $conflict($this->actingAs($manager)->deleteJson("/sessions/{$session->id}/seances/{$empty->id}"));
    $conflict($this->actingAs($manager)->postJson("/sessions/{$session->id}/seances", [...$base, 'date' => '2026-09-10']));
    $conflict($this->actingAs($manager)->postJson("/sessions/{$session->id}/seances/{$empty->id}/cancel", ['reason' => 'Grève']));
    $conflict($this->actingAs($manager)->putJson("/sessions/{$session->id}/seances/{$october->id}", [...$base, 'date' => '2026-09-15']));
    // The generator creates nothing in the month.
    $created = app(SeanceGenerator::class)->generate($module, GenerationRequest::fromInput([
        'from' => '2026-09-21', 'to' => '2026-10-09', 'weekdays' => [1], 'slots' => [['start' => '14:00', 'end' => '17:00']],
    ], $module));
    expect(collect($created)->map(fn (Seance $s) => $s->date->toDateString())->all())->toBe(['2026-10-05']);

    // Nothing changed; the trainer or the note of a séance still change.
    expect(Attendance::query()->sole()->status)->toBe('present')
        ->and(DB::table('monthly_hours')->value('minutes'))->toBe(600)
        ->and($seance->refresh()->end())->toBe('12:00')
        ->and(Seance::query()->find($empty->id))->not->toBeNull();
    app(Seances::class)->reschedule($seance, ['note' => 'Salle B']);
    // The refused moves rewrote no attendance of the closed month.
    expect(Attendance::query()->sole()->minutes)->toBe(180)->and(Attendance::query()->sole()->needs_review)->toBeFalse();
    // October stays open.
    app(Attendances::class)->record($october, $lea->id, 'present', null, null);
});

it('refuses to reopen without a reason, reopens with one, journaled without it in clear', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $manager = atManager();
    $this->actingAs($manager)->postJson("/sessions/{$session->id}/closures", ['month' => '2026-09'])->assertOk();

    $this->actingAs($manager)->postJson("/sessions/{$session->id}/closures/reopen", ['month' => '2026-09', 'reason' => '  '])
        ->assertStatus(422)->assertJsonValidationErrors(['reason' => 'Indiquez le motif de la réouverture.']);
    expect(app(Closures::class)->isClosed($session, CarbonImmutable::parse('2026-09-15')))->toBeTrue();

    $this->actingAs($manager)->postJson("/sessions/{$session->id}/closures/reopen", ['month' => '2026-09', 'reason' => 'Erreur sur la présence de Léa Durand'])
        ->assertOk();
    expect(app(Closures::class)->isClosed($session, CarbonImmutable::parse('2026-09-15')))->toBeFalse();
    $closure = AttendanceClosure::query()->sole();
    expect($closure->reopen_reason)->toBe('Erreur sur la présence de Léa Durand')
        ->and($closure->reopened_by)->toBe($manager->id)
        ->and($closure->closed_at)->toBeNull();

    $logs = DB::table('audit_logs')->where('auditable_type', 'attendance_closure')->orderBy('created_at')->get();
    expect($logs->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($logs->map(fn ($l) => $l->old_values.' '.$l->new_values)->implode(' '))->not->toContain('Léa')->not->toContain('Erreur');
    expect(json_decode((string) $logs[1]->new_values, true))->toMatchArray(['reopened_by' => $manager->id, 'reopen_reason' => '[donnée personnelle]']);

    // Not closed: nothing to reopen. Closed again: locked again.
    $this->actingAs($manager)->postJson("/sessions/{$session->id}/closures/reopen", ['month' => '2026-09', 'reason' => 'Encore'])->assertStatus(409);
    $this->actingAs($manager)->postJson("/sessions/{$session->id}/closures", ['month' => '2026-09'])->assertOk();
    expect(app(Closures::class)->isClosed($session, CarbonImmutable::parse('2026-09-15')))->toBeTrue()
        ->and(AttendanceClosure::query()->count())->toBe(1);
});

it('closes and reopens with sessions.attendance.close on the session only', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $enterer = l2User([Permissions::SESSIONS_VIEW => null, Permissions::ATTENDANCE_MANAGE => null]);
    $own = l2User([Permissions::SESSIONS_VIEW => null, Permissions::ATTENDANCE_CLOSE => AccessScope::Own]);

    $this->actingAs($enterer)->postJson("/sessions/{$session->id}/closures", ['month' => '2026-09'])->assertForbidden();
    $this->actingAs($own)->postJson("/sessions/{$session->id}/closures", ['month' => '2026-09'])->assertForbidden();
    expect(AttendanceClosure::query()->count())->toBe(0);
});

it('sends the « Présences » tab of a session: months, séances, monthly grid and realised hours', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $module = plModule($session);
    $interval = atIntervalModule($session);
    $seance = plSeance($module, '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
    app(MonthlyHours::class)->record($lea->id, $interval, '2026-09', 600, null);
    app(Closures::class)->close($session, '2026-09');

    $this->actingAs(atManager())->getJson("/sessions/{$session->id}/attendance?month=2026-09")->assertOk()
        ->assertJsonPath('month', '2026-09')
        ->assertJsonPath('months.0.value', '2026-09')
        ->assertJsonPath('months.0.label', 'septembre 2026')
        ->assertJsonPath('months.0.closed', true)
        ->assertJsonPath('months.1.closed', false)
        ->assertJsonPath('closure.closed', true)
        ->assertJsonPath('seances.0.id', $seance->id)
        ->assertJsonPath('seances.0.recorded', 1)
        ->assertJsonPath('seances.0.expected', 1)
        ->assertJsonPath('monthly.modules.0.id', $interval->id)
        ->assertJsonPath('monthly.rows.0.name', 'Léa Durand')
        ->assertJsonPath("monthly.rows.0.minutes.{$interval->id}", 600)
        ->assertJsonPath('realised.0.name', 'Léa Durand')
        ->assertJsonPath('realised.0.realised_minutes', 780)
        ->assertJsonPath('realised.0.realised', '13 h')
        ->assertJsonPath('can.close', true);
    expect(SessionModule::query()->count())->toBe(2);
});
