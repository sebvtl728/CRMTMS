<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Permissions;
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

/** Opens the morning of the séance's day by HTTP, returns [slot id, public url]. */
function hOpen(string $seanceId, $user = null): array
{
    $json = test()->actingAs($user ?? atManager())->postJson("/seances/{$seanceId}/signing-slots", ['half' => 'morning'])
        ->assertCreated()->json();

    return [$json['slot_id'], $json['public_url']];
}

function hToken(string $url): string
{
    return substr($url, strrpos($url, '/') + 1);
}

it('opens a half-day and shows the trainer its QR code and the expected trainees', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');
    $lea = atEnrol($session, 'Durand');
    [$slotId, $url] = hOpen($seance->id);

    expect($url)->toMatch('#/emargement/[A-Za-z0-9]{40}$#');
    $this->getJson("/sessions/emargement/{$slotId}/state")->assertOk()
        ->assertJsonPath('status', 'open')->assertJsonPath('public_url', $url)
        ->assertJsonPath('rows.0.enrolment_id', $lea->id)->assertJsonPath('rows.0.name', 'Léa Durand')
        ->assertJsonPath('rows.0.signed_at', null)
        ->assertJson(fn ($json) => $json->where('qr_svg', fn ($svg) => str_starts_with((string) $svg, '<svg'))->etc());
    $this->get("/sessions/emargement/{$slotId}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('sessions/attendance-signing')->where('state.status', 'open'));
});

it('lets a trainee sign on the public page, which shows the first name and initial only', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');
    $lea = atEnrol($session, 'Durand');
    [$slotId, $url] = hOpen($seance->id);
    auth()->logout();
    $token = hToken($url);

    $page = $this->get("/emargement/{$token}")->assertOk();
    $page->assertInertia(fn (AssertableInertia $p) => $p->component('public/attendance-signing')->where('state', 'open')
        ->where('half_label', 'Matin')->where('trainees.0.id', $lea->id)->where('trainees.0.label', 'Léa D.'));
    expect((string) $page->getContent())->not->toContain('Durand')->not->toContain('@');

    $this->postJson("/emargement/{$token}", ['enrolment_id' => $lea->id, 'drawing' => sigDrawing()])->assertCreated();
    $this->postJson("/emargement/{$token}", ['enrolment_id' => $lea->id, 'drawing' => sigDrawing()])->assertStatus(409)->assertJsonPath('message', 'Ce nom a déjà signé.');
    $this->get("/emargement/{$token}")->assertInertia(fn (AssertableInertia $p) => $p->where('trainees', [])->where('expected', 1));

    $this->actingAs(atManager())->getJson("/sessions/emargement/{$slotId}/state")->assertJsonPath('rows.0.signed_at', fn ($v) => $v !== null);
});

it('answers the same 404 page for every invalid link', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');
    [$slotId, $url] = hOpen($seance->id);
    $token = hToken($url);
    $this->postJson("/sessions/emargement/{$slotId}/close")->assertOk();
    auth()->logout();

    $bodies = [];
    foreach ([$token, str_repeat('a', 40), 'abc'] as $t) {
        $r = $this->get("/emargement/{$t}")->assertNotFound();
        if ($t !== 'abc') {
            $r->assertInertia(fn (AssertableInertia $p) => $p->component('public/attendance-signing')->where('state', 'invalid'));
            // Same props (the nonce of the page changes on every answer).
            $bodies[] = json_encode($r->viewData('page')['props']);
        }
    }
    expect($bodies[0])->toBe($bodies[1]);
    $this->postJson("/emargement/{$token}", ['enrolment_id' => 'x', 'drawing' => sigDrawing()])->assertNotFound();
});

it('lets a whole class behind one address sign', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');
    $trainees = collect(range(1, 10))->map(fn (int $i) => atEnrol($session, 'Nom'.$i));
    [, $url] = hOpen($seance->id);
    auth()->logout();
    $token = hToken($url);

    foreach ($trainees as $e) {
        $this->get("/emargement/{$token}")->assertOk();
        $this->postJson("/emargement/{$token}", ['enrolment_id' => $e->id, 'drawing' => sigDrawing()])->assertCreated();
        // A second tap on the same name: refused, never throttled.
        $this->postJson("/emargement/{$token}", ['enrolment_id' => $e->id, 'drawing' => sigDrawing()])->assertStatus(409);
    }
    expect(AttendanceSignature::query()->count())->toBe(10);
});

it('keeps another trainer out of the slot (404) and lets the manager erase and validate', function () {
    $mine = userWithRole(Role::Formateur);
    $trainer = Trainers::forUser($mine) ?? Trainers::ensureForUser($mine);
    $other = userWithRole(Role::Formateur);
    Trainers::forUser($other) ?? Trainers::ensureForUser($other);
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00', ['trainer_id' => $trainer->id]);
    $lea = atEnrol($session, 'Durand');
    [$slotId, $url] = hOpen($seance->id, $mine);

    $this->actingAs($other)->getJson("/sessions/emargement/{$slotId}/state")->assertNotFound();
    $this->actingAs($other)->postJson("/seances/{$seance->id}/signing-slots", ['half' => 'morning'])->assertNotFound();

    $this->postJson('/emargement/'.hToken($url), ['enrolment_id' => $lea->id, 'drawing' => sigDrawing()])->assertCreated();
    $signature = AttendanceSignature::query()->sole();
    $this->actingAs($mine)->deleteJson("/sessions/emargement/{$slotId}/signatures/{$signature->id}")->assertOk();
    $this->postJson('/emargement/'.hToken($url), ['enrolment_id' => $lea->id, 'drawing' => sigDrawing()])->assertCreated();

    $this->actingAs($mine)->postJson("/sessions/emargement/{$slotId}/validate", ['drawing' => sigDrawing()])->assertOk()
        ->assertJsonPath('status', 'validated');
    expect(SignSlot::query()->sole()->validator_role)->toBe('trainer');
    $this->actingAs($mine)->getJson("/seances/{$seance->id}/attendance")->assertJsonPath('rows.0.status', 'present')
        ->assertJsonPath('slots.0.half', 'morning')->assertJsonPath('slots.0.status', 'validated')->assertJsonPath('slots.0.signed', 1);
});

it('lists the half-days of the month on the « Présences » tab, the past open ones to validate', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');
    atEnrol($session, 'Durand');
    hOpen($seance->id);
    sgNow('2026-09-11 09:00');

    $this->flushSession();
    $this->actingAs(atManager())->getJson("/sessions/{$session->id}/attendance?month=2026-09")->assertOk()
        ->assertJsonPath('slots.0.date', '2026-09-10')->assertJsonPath('slots.0.half', 'morning')
        ->assertJsonPath('slots.0.to_validate', true)->assertJsonPath('slots.0.expected', 1);
});

it('tells the « Présences » tab whether a séance\'s day is to come, so the screen explains the missing button', function () {
    $session = plSession();
    $past = plSeance(plModule($session), '2026-09-09');
    $today = plSeance(plModule($session), '2026-09-10');
    $later = plSeance(plModule($session), '2026-09-14');
    atEnrol($session, 'Durand');
    $this->actingAs(atManager());

    $this->getJson("/seances/{$later->id}/attendance")->assertOk()
        ->assertJsonPath('slots.0.is_today', false)->assertJsonPath('slots.0.is_future', true);
    $this->getJson("/seances/{$today->id}/attendance")->assertOk()
        ->assertJsonPath('slots.0.is_today', true)->assertJsonPath('slots.0.is_future', false);
    $this->getJson("/seances/{$past->id}/attendance")->assertOk()
        ->assertJsonPath('slots.0.is_today', false)->assertJsonPath('slots.0.is_future', false);
});

it('tells the public page that nobody is expected, rather than that everyone has signed', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');
    atEnrol($session, 'Durand', ['entered_on' => '2026-09-14']);
    [, $url] = hOpen($seance->id);
    auth()->logout();

    $this->get('/emargement/'.hToken($url))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('trainees', [])->where('expected', 0));
});

it('lets who holds « Faire entrer un stagiaire » bring a trainee in on the half-day, who can then sign', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');
    $lea = atEnrol($session, 'Durand', ['entered_on' => '2026-09-14']);
    $manager = userWithPermissions([
        Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW, Permissions::ATTENDANCE_MANAGE, Permissions::ATTENDANCE_ADVANCE_ENTRY,
    ]);
    [$slotId, $url] = hOpen($seance->id, $manager);

    $this->actingAs($manager)->getJson("/sessions/emargement/{$slotId}/state")->assertOk()
        ->assertJsonPath('rows', [])->assertJsonPath('can_advance_entry', true)
        ->assertJsonPath('late_entrants.0.enrolment_id', $lea->id)->assertJsonPath('late_entrants.0.entered_on', '2026-09-14');

    // Without the right: listed, not allowed.
    $this->actingAs(atManager())->getJson("/sessions/emargement/{$slotId}/state")->assertJsonPath('can_advance_entry', false);
    $this->actingAs(atManager())->postJson("/sessions/emargement/{$slotId}/advance-entry", ['enrolment_id' => $lea->id])->assertForbidden();

    $this->actingAs($manager)->postJson("/sessions/emargement/{$slotId}/advance-entry", ['enrolment_id' => $lea->id])->assertOk()
        ->assertJsonPath('rows.0.enrolment_id', $lea->id)->assertJsonPath('late_entrants', []);
    expect($lea->refresh()->entered_on->toDateString())->toBe('2026-09-10');

    auth()->logout();
    $this->get('/emargement/'.hToken($url))->assertInertia(fn (AssertableInertia $p) => $p->where('trainees.0.id', $lea->id));
});

it('refuses to bring in a trainee of another session', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');
    $other = atEnrol(plSession(), 'Martin', ['entered_on' => '2026-09-14']);
    $manager = userWithPermissions([
        Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW, Permissions::ATTENDANCE_MANAGE, Permissions::ATTENDANCE_ADVANCE_ENTRY,
    ]);
    [$slotId] = hOpen($seance->id, $manager);

    $this->actingAs($manager)->postJson("/sessions/emargement/{$slotId}/advance-entry", ['enrolment_id' => $other->id])->assertNotFound();
    expect($other->refresh()->entered_on->toDateString())->toBe('2026-09-14');
});

it('keeps the half-days and their signatures in every answer of the attendance dialog (« Tous présents », a status, a clear)', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Martin');
    [, $url] = hOpen($seance->id);
    auth()->logout();
    $this->postJson('/emargement/'.hToken($url), ['enrolment_id' => $lea->id, 'drawing' => sigDrawing()])->assertCreated();
    $manager = atManager();

    $this->actingAs($manager)->postJson("/seances/{$seance->id}/attendance/all-present")->assertOk()
        ->assertJsonPath('count', 2)->assertJsonPath('slots.0.half', 'morning')->assertJsonPath('slots.0.signed', 1);
    $this->postJson("/seances/{$seance->id}/attendance", ['enrolment_id' => $paul->id, 'status' => 'absent', 'minutes' => null, 'reason' => ''])->assertOk()
        ->assertJsonPath('slots.0.signed', 1);
    $this->deleteJson("/seances/{$seance->id}/attendance/{$paul->id}")->assertOk()
        ->assertJsonPath('slots.0.signed', 1);
});
