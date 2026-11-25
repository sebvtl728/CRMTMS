<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Sessions\Attendance\TraineeFollowUp;
use App\Modules\Sessions\Enrolments\EnrolmentRows;
use App\Modules\Sessions\Gdpr\EnrolmentAnonymisation;
use App\Modules\Sessions\Gdpr\PersonSessionsExport;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\EnrolmentAdjustment;
use App\Modules\Sessions\Planning\IndividualPlanningWriter as Writer;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
});

function iphManager()
{
    return userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SESSIONS_UPDATE, Permissions::SEANCES_VIEW, Permissions::ATTENDANCE_MANAGE]);
}

it('gives the individual planning of a trainee', function () {
    $session = plSession();
    $module = plModule($session);
    $first = plSeance($module, '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $second = plSeance($module, '2026-09-03', '09:00', '12:30');
    $lea = atEnrol($session);
    $this->actingAs(iphManager());
    app(Writer::class)->setOwnHours($lea, $first->id, '14:00', '17:00', 0);
    app(Writer::class)->exemptSeances($lea, [$second->id], 'VAE');

    $json = $this->getJson("/enrolments/{$lea->id}/planning")->assertOk()->json();

    expect($json['base'])->toBe('21 h')->and($json['planned'])->toBe('13 h 30')->and($json['can_edit'])->toBeTrue()
        ->and($json['modules'][0])->toMatchArray(['id' => $module->id, 'mode' => 'seances', 'exempted' => false])
        ->and($json['seances'])->toHaveCount(2)
        ->and($json['seances'][0])->toMatchArray(['id' => $first->id, 'date' => '2026-09-02', 'start' => '14:00', 'end' => '17:00',
            'seance_start' => '09:00', 'seance_end' => '17:00', 'kind' => 'normal', 'own_hours' => true, 'has_attendance' => false])
        ->and($json['seances'][1])->toMatchArray(['id' => $second->id, 'kind' => 'exempted', 'reason' => 'VAE']);
});

it('exempts, sets own hours and removes through the routes', function () {
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    $this->actingAs(iphManager());

    $this->post("/enrolments/{$lea->id}/planning/exemptions", ['seance_ids' => [$seance->id], 'reason' => 'VAE'])->assertRedirect();
    expect($lea->refresh()->planned_hours)->toBe('14.00');
    $adjustment = EnrolmentAdjustment::query()->sole();
    $this->delete("/enrolments/{$lea->id}/planning/adjustments/{$adjustment->id}")->assertRedirect();
    expect(EnrolmentAdjustment::query()->count())->toBe(0);

    $this->put("/enrolments/{$lea->id}/planning/seances/{$seance->id}/hours", ['starts_at' => '14:00', 'ends_at' => '17:00', 'break_minutes' => 0])->assertRedirect();
    expect(EnrolmentAdjustment::query()->sole()->kind)->toBe('own_hours');
    $this->delete("/enrolments/{$lea->id}/planning/seances/{$seance->id}/hours")->assertRedirect();

    $this->post("/enrolments/{$lea->id}/planning/exemptions", ['module_id' => $module->id])->assertRedirect();
    expect(EnrolmentAdjustment::query()->sole()->kind)->toBe('exempt_module');
});

it('refuses without the update right and hides another scope', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);

    $this->actingAs(userWithPermissions([Permissions::SESSIONS_VIEW]));
    $this->getJson("/enrolments/{$lea->id}/planning")->assertForbidden();
    $this->post("/enrolments/{$lea->id}/planning/exemptions", ['seance_ids' => [$seance->id]])->assertForbidden();

    $this->actingAs(l2User([Permissions::SESSIONS_VIEW => AccessScope::Own, Permissions::SESSIONS_UPDATE => AccessScope::Own]));
    $this->getJson("/enrolments/{$lea->id}/planning")->assertNotFound();
});

it('erases the reasons when the trainee is anonymised', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    $this->actingAs(iphManager());
    app(Writer::class)->exemptSeances($lea, [$seance->id], 'Raison médicale');

    EnrolmentAnonymisation::scrub([(string) $lea->person_id]);

    expect(EnrolmentAdjustment::query()->sole()->reason)->toBeNull();
});

it('exports the individual planning with the trainee data', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    $this->actingAs(iphManager());
    app(Writer::class)->setOwnHours($lea, $seance->id, '14:00', '17:00', 0);

    $export = PersonSessionsExport::for((string) $lea->person_id);

    expect($export['inscriptions'][0]['planning_individuel'])->toBe([
        ['type' => 'Horaires individuels', 'seance' => '2026-09-02', 'module' => null, 'horaires' => '14:00–17:00', 'motif' => null],
    ]);
});

it('offers the individual planning from the training follow-up to who may change the enrolment', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);

    $manager = iphManager();
    $reader = userWithPermissions([Permissions::SESSIONS_VIEW]);

    expect(TraineeFollowUp::of($lea->dossier_id, (string) $lea->person_id, $manager)['planning_enrolment_id'] ?? null)->toBe($lea->id)
        ->and(TraineeFollowUp::of($lea->dossier_id, (string) $lea->person_id, $reader))->not->toHaveKey('planning_enrolment_id');
});

it('tells the enrolment list the base of an individual planning', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:30');
    $lea = atEnrol($session);
    $this->actingAs(iphManager());
    app(Writer::class)->exemptSeances($lea, [$seance->id], null);

    $row = collect(EnrolmentRows::forSession($session->refresh(), iphManager()))->firstWhere('id', $lea->id);

    expect($row['planned_hours'])->toBe('17,50')->and($row['planned_hours_base'])->toBe('21,00');
});

it('offers the séances of the sessions the user sees and adds them', function () {
    $own = plSession();
    plSeance(plModule($own), '2026-09-02', '09:00', '12:30');
    $host = plSession('2026-09-01', '2026-09-30', ['title' => 'Groupe rattrapage']);
    $clash = plSeance(plModule($host), '2026-09-02', '10:00', '12:00');
    $free = plSeance(plModule($host), '2026-09-09', '09:00', '12:30');
    $lea = atEnrol($own);
    $this->actingAs(iphManager());

    $json = $this->getJson("/enrolments/{$lea->id}/planning/available?q=rattrapage&session={$host->id}")->assertOk()->json();

    expect(array_column($json['sessions'], 'id'))->toBe([$host->id])
        ->and(array_column($json['seances'], 'conflict', 'id'))->toBe([$clash->id => true, $free->id => false]);

    $this->post("/enrolments/{$lea->id}/planning/additions", ['seance_ids' => [$free->id], 'reason' => 'Rattrapage'])->assertRedirect();
    $planning = $this->getJson("/enrolments/{$lea->id}/planning")->json();
    expect(collect($planning['seances'])->firstWhere('id', $free->id))->toMatchArray(['kind' => 'added'])
        ->and(collect($planning['seances'])->firstWhere('id', $free->id)['session_label'])->toContain('Groupe rattrapage');
});
