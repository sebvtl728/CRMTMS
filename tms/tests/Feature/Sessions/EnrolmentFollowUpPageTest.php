<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';
require_once __DIR__.'/Support/signing.php';

beforeEach(function () {
    plSetUp();
    Storage::fake(TenantStorage::DISK);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A September session with two séances, Léa and Paul enrolled, Léa present
 * on the 8th (no candidature: the follow-up comes from the session).
 *
 * @return array{session: TrainingSession, lea: Enrolment, paul: Enrolment}
 */
function efSetUp(): array
{
    sgNow('2026-09-08 15:00');
    $session = plSession();
    $module = plModule($session);
    $past = plSeance($module, '2026-09-08', '09:00', '12:30');
    plSeance($module, '2026-09-15', '13:30', '17:00');
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    test()->actingAs(atManager());
    app(Attendances::class)->record($past, $lea->id, 'present', null, null);

    return ['session' => $session, 'lea' => $lea, 'paul' => $paul];
}

it('shows the planning, attendance and signatures of an enrolled trainee from the session', function () {
    ['session' => $session, 'lea' => $lea] = efSetUp();

    $this->actingAs(atManager())->get("/sessions/{$session->id}/stagiaires/{$lea->id}/suivi")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sessions/sessions/enrolment-follow-up')
            ->where('trainee.label', fn ($label) => str_contains((string) $label, 'Durand'))
            ->where('back', "/sessions/{$session->id}?etape=stagiaires")
            ->has('follow_up.seances', 2)
            ->where('follow_up.seances.0.attendance.status', 'present')
            ->where('follow_up.enrolment_id', $lea->id)
            ->has('follow_up.signatures')
            ->has('follow_up.hours'));
});

it('answers 404 for an enrolment of another session, a waitlisted one or out of scope', function () {
    ['session' => $session, 'lea' => $lea] = efSetUp();
    $other = plSession('2026-10-01', '2026-10-31');
    $elsewhere = atEnrol($other, 'Martin');

    $this->actingAs(atManager())->get("/sessions/{$session->id}/stagiaires/{$elsewhere->id}/suivi")->assertNotFound();

    $lea->forceFill(['status' => 'waitlisted', 'waitlist_position' => 1])->save();
    $this->get("/sessions/{$session->id}/stagiaires/{$lea->id}/suivi")->assertNotFound();

    $this->actingAs(l2User([Permissions::SESSIONS_VIEW => AccessScope::Own]))
        ->get("/sessions/{$session->id}/stagiaires/{$elsewhere->id}/suivi")->assertNotFound();
});

it('gives the planning without attendance to a viewer without the attendance right', function () {
    ['session' => $session, 'lea' => $lea] = efSetUp();

    $this->actingAs(l2User([Permissions::SESSIONS_VIEW => null]))->get("/sessions/{$session->id}/stagiaires/{$lea->id}/suivi")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('follow_up.seances', 2)
            ->missing('follow_up.signatures')
            ->missing('follow_up.seances.0.attendance'));
});

it('offers the follow-up link on each enrolment row of the session', function () {
    ['session' => $session, 'lea' => $lea] = efSetUp();

    $this->actingAs(atManager())->get("/sessions/{$session->id}?etape=stagiaires")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('enrolments.0.follow_up_url', fn ($url) => str_ends_with((string) $url, '/suivi')));
});
