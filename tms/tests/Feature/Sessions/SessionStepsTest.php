<?php

use App\Modules\Core\References\ReferenceList;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
});

it('exposes the checklist and the visible steps', function () {
    $session = plSession();
    $manager = userWithPermissions([
        Permissions::SESSIONS_VIEW, Permissions::SESSIONS_UPDATE, Permissions::SEANCES_VIEW, Permissions::SEANCES_MANAGE,
        Permissions::DOSSIERS_VIEW, Permissions::ATTENDANCE_MANAGE, Permissions::ATTENDANCE_CLOSE,
    ]);

    $this->actingAs($manager)->get("/sessions/{$session->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sessions/sessions/show')
        ->where('steps', ['synthese', 'configuration', 'planning', 'stagiaires', 'suivi'])
        ->where('checklist.0.key', 'hours')
        ->where('checklist.0.step', 'planning')
        ->where('checklist.0.anchor', 'seances')
        ->has('checklist.0', fn (Assert $card) => $card->hasAll(['key', 'title', 'value', 'state', 'text', 'step', 'anchor'])));

    // Without attendance rights: no « Suivi » step (and no attendance card).
    $viewer = userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW]);
    $this->actingAs($viewer)->get("/sessions/{$session->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('steps', ['synthese', 'configuration', 'planning', 'stagiaires'])
        ->where('checklist', fn ($cards) => collect($cards)->pluck('key')->doesntContain('attendance')
            && collect($cards)->pluck('key')->contains('hours')));

    // A cancelled session: no card (the page shows the cancellation banner).
    $this->actingAs($manager);
    app(SessionWriter::class)->cancel($session, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);
    $this->get("/sessions/{$session->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('checklist', [])
        ->where('steps', ['synthese', 'configuration', 'planning', 'stagiaires', 'suivi']));
});
