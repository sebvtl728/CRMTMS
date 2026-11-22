<?php

use App\Modules\Sessions\Sessions\SessionColors;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
});

it('gives each new session its own colour of the palette, in turn', function () {
    $colors = collect(range(1, count(SessionColors::PALETTE) + 1))->map(fn () => plSession()->color)->all();

    expect(array_slice($colors, 0, count(SessionColors::PALETTE)))->toBe(SessionColors::PALETTE)
        ->and($colors[count(SessionColors::PALETTE)])->toBe(SessionColors::PALETTE[0]);
});

it('paints the séances of the planning in the colour of their session', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-02', '09:00', '12:00');

    $this->actingAs(atManager())->get('/sessions/planning?date=2026-09-02')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('seances.0.session.color', $session->color));
});

it('shows the colour on the session itself: list and page', function () {
    $session = plSession();
    $this->actingAs(atManager());

    $this->get('/sessions?view=list')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('sessions.data.0.color', $session->color));
    $this->get("/sessions/{$session->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('session.color', $session->color));
});
