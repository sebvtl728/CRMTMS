<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Public\AttendanceSigning;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../Support/lot2a.php';
require_once __DIR__.'/../Support/planning.php';
require_once __DIR__.'/../Support/attendance.php';
require_once __DIR__.'/../Support/signing.php';

beforeEach(function () {
    plSetUp();
    Storage::fake(TenantStorage::DISK);
    sgNow();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Opens the on-site catch-up of a slot by HTTP: the public token. */
function osOpen(string $slotId): string
{
    $url = test()->actingAs(atManager())->postJson("/sessions/emargement/{$slotId}/catch-up")->assertOk()->json('catch_up.public_url');

    return substr((string) $url, strrpos((string) $url, '/') + 1);
}

it('opens an on-site catch-up on a past half-day and lets an unsigned trainee sign', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    $slotId = sgOpen($session)['slot']->id;
    app(AttendanceSigning::class)->recordExternal($slotId, $paul->id, CarbonImmutable::now(), null, 'fake');
    app(AttendanceSigning::class)->validateExternal($slotId, 'fake', null, 'Marie Martin');
    sgNow('2026-09-11 10:00');

    $token = osOpen($slotId);
    auth()->logout();
    $this->get("/emargement/r/{$token}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('public/attendance-signing')->where('mode', 'catch_up_onsite')
            ->where('trainees', [['id' => $lea->id, 'label' => 'Léa D.']])
            ->where('banner', 'Rattrapage du jeudi 10 septembre 2026, matin'));
    $this->postJson("/emargement/r/{$token}", ['enrolment_id' => $lea->id, 'drawing' => sigDrawing()])->assertCreated();

    expect(AttendanceSignature::query()->where('enrolment_id', $lea->id)->sole())
        ->kind->toBe(AttendanceSignature::KIND_ONSITE)->confirmed_at->toBeNull();
    expect(Attendance::query()->where('enrolment_id', $lea->id)->sole()->status)->toBe('absent');
});

it('refuses an on-site catch-up today, on a never-opened day and in a closed month', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    atEnrol($session, 'Durand');
    $slotId = sgOpen($session)['slot']->id;
    $this->actingAs(atManager());

    $this->postJson("/sessions/emargement/{$slotId}/catch-up")->assertStatus(409)
        ->assertJsonPath('message', 'Le jour même, utilisez l\'émargement en ligne.');
    $this->postJson('/sessions/emargement/'.fake()->uuid().'/catch-up')->assertNotFound();

    sgNow('2026-10-02 10:00');
    app(Closures::class)->close($session, '2026-09');
    $this->flushSession();
    $this->actingAs(atManager())->postJson("/sessions/emargement/{$slotId}/catch-up")->assertStatus(409)
        ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains((string) $m, 'est clôturé'))->etc());
});

it('ends the catch-up link at the end of the day and when closed', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    atEnrol($session, 'Durand');
    $slotId = sgOpen($session)['slot']->id;
    sgNow('2026-09-11 10:00');

    $token = osOpen($slotId);
    sgNow('2026-09-12 00:01');
    $this->flushSession();
    auth()->logout();
    $this->get("/emargement/r/{$token}")->assertNotFound();

    $token = osOpen($slotId);
    $this->actingAs(atManager())->postJson("/sessions/emargement/{$slotId}/catch-up/close")->assertOk();
    auth()->logout();
    $this->get("/emargement/r/{$token}")->assertNotFound();
});

it('refuses a second signature for a trainee signed meanwhile', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slotId = sgOpen($session)['slot']->id;
    sgNow('2026-09-11 10:00');
    $token = osOpen($slotId);

    app(AttendanceSigning::class)->recordExternal($slotId, $lea->id, CarbonImmutable::now(), null, 'fake');
    auth()->logout();
    $this->get("/emargement/r/{$token}")->assertInertia(fn (AssertableInertia $p) => $p->where('trainees', []));
    $this->postJson("/emargement/r/{$token}", ['enrolment_id' => $lea->id, 'drawing' => sigDrawing()])->assertStatus(409)
        ->assertJsonPath('message', 'Ce nom a déjà signé.');
});

it('never lets the normal link of a past day be used for signing', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    atEnrol($session, 'Durand');
    $token = sgOpen($session)['token'];
    sgNow('2026-09-11 10:00');

    $this->get("/emargement/{$token}")->assertNotFound();
    $this->get("/emargement/r/{$token}")->assertNotFound();
});

it('reserves opening a catch-up to managers: a trainer sees the half-day, never opens one', function () {
    $formateur = userWithRole(Role::Formateur);
    $trainer = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00', ['trainer_id' => $trainer->id]);
    $lea = atEnrol($session, 'Durand');
    $slotId = sgOpen($session)['slot']->id;
    sgNow('2026-09-11 10:00');

    $this->actingAs($formateur)->getJson("/sessions/emargement/{$slotId}/state")->assertOk()->assertJsonPath('can_catch_up', false);
    $this->postJson("/sessions/emargement/{$slotId}/catch-up")->assertForbidden();
    $this->postJson("/sessions/emargement/{$slotId}/catch-up/close")->assertForbidden();
    $this->postJson("/sessions/emargement/{$slotId}/catch-up-links", ['enrolment_id' => $lea->id, 'email' => 'lea@example.test'])->assertForbidden();

    $this->flushSession();
    $this->actingAs(atManager())->getJson("/sessions/emargement/{$slotId}/state")->assertOk()->assertJsonPath('can_catch_up', true);
});
