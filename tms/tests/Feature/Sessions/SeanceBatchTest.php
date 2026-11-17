<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\Seances;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
});

it('changes the trainer, the room or place and the hours of several séances at once', function () {
    $session = plSession();
    $module = plModule($session);
    $a = plSeance($module, '2026-09-07');
    $b = plSeance($module, '2026-09-08', '09:00', '12:30', ['place_text' => 'Distanciel']);
    $other = plSeance($module, '2026-09-09');
    $trainer = plTrainer();
    $room = plRoom();

    $this->actingAs(plManager())->post("/sessions/{$session->id}/seances/batch", [
        'seance_ids' => [$a->id, $b->id],
        'apply_trainer' => true, 'trainer_id' => $trainer->id,
        'apply_place' => true, 'room_id' => $room->id, 'place_text' => '',
        'apply_hours' => true, 'starts_at' => '13:30', 'ends_at' => '17:00', 'break_minutes' => '15',
    ])->assertRedirect("/sessions/{$session->id}")->assertSessionHasNoErrors()->assertSessionHas('status', '2 séances modifiées.');

    foreach ([$a, $b] as $s) {
        $s->refresh();
        expect($s->trainer_id)->toBe($trainer->id)->and($s->room_id)->toBe($room->id)->and($s->place_text)->toBeNull()
            ->and($s->start())->toBe('13:30')->and($s->end())->toBe('17:00')->and($s->break_minutes)->toBe(15);
    }
    expect($a->date->toDateString())->toBe('2026-09-07');
    $other->refresh();
    expect($other->trainer_id)->toBeNull()->and($other->start())->toBe('09:00');
});

it('keeps what is not ticked and can remove the trainer of several séances', function () {
    $session = plSession();
    $module = plModule($session);
    $trainer = plTrainer();
    $room = plRoom();
    $a = plSeance($module, '2026-09-07', '09:00', '12:30', ['trainer_id' => $trainer->id, 'room_id' => $room->id]);
    $b = plSeance($module, '2026-09-08', '09:00', '12:30', ['trainer_id' => $trainer->id, 'room_id' => $room->id]);

    $this->actingAs(plManager())->post("/sessions/{$session->id}/seances/batch", [
        'seance_ids' => [$a->id, $b->id], 'apply_trainer' => true, 'trainer_id' => '',
        'room_id' => '', 'starts_at' => '14:00',
    ])->assertSessionHasNoErrors();

    foreach ([$a, $b] as $s) {
        $s->refresh();
        expect($s->trainer_id)->toBeNull()->and($s->room_id)->toBe($room->id)->and($s->start())->toBe('09:00');
    }
});

it('changes nothing when one séance is refused, and names it', function () {
    $session = plSession();
    $module = plModule($session);
    $a = plSeance($module, '2026-09-07');
    $b = plSeance($module, '2026-09-08');
    plSeance($module, '2026-09-08', '14:00', '17:00');

    $this->actingAs(plManager())->post("/sessions/{$session->id}/seances/batch", [
        'seance_ids' => [$a->id, $b->id], 'apply_hours' => true, 'starts_at' => '14:00', 'ends_at' => '17:00', 'break_minutes' => '0',
    ])->assertSessionHasErrors(['batch' => 'Séance du 08/09/2026 : Une séance de ce module commence déjà à cette heure ce jour-là.']);

    expect($a->refresh()->start())->toBe('09:00')->and($b->refresh()->start())->toBe('09:00');
});

it('validates the batch in French', function () {
    $session = plSession();
    $module = plModule($session);
    $a = plSeance($module, '2026-09-07');
    $url = "/sessions/{$session->id}/seances/batch";

    $this->actingAs(plManager())->post($url, ['seance_ids' => [], 'apply_trainer' => true])
        ->assertSessionHasErrors(['seance_ids' => 'Cochez au moins une séance.']);
    $this->actingAs(plManager())->post($url, ['seance_ids' => [$a->id]])
        ->assertSessionHasErrors(['batch' => 'Cochez au moins un changement : formateur, salle ou lieu, horaires.']);
    $this->actingAs(plManager())->post($url, ['seance_ids' => [$a->id], 'apply_hours' => true, 'starts_at' => '12:00', 'ends_at' => '10:00'])
        ->assertSessionHasErrors(['ends_at' => 'L\'heure de fin doit suivre l\'heure de début.']);
    $this->actingAs(plManager())->post($url, ['seance_ids' => [$a->id], 'apply_trainer' => true, 'trainer_id' => '01900000-0000-7000-8000-000000000000'])
        ->assertSessionHasErrors(['trainer_id' => 'Formateur introuvable.']);
});

it('refuses a séance of another session (404) and a cancelled one (409)', function () {
    $session = plSession();
    $module = plModule($session);
    $a = plSeance($module, '2026-09-07');
    $otherSession = plSession();
    $foreign = plSeance(plModule($otherSession), '2026-09-07');

    $this->actingAs(plManager())->post("/sessions/{$session->id}/seances/batch", ['seance_ids' => [$a->id, $foreign->id], 'apply_trainer' => true, 'trainer_id' => ''])
        ->assertNotFound();

    app(Seances::class)->cancel($a, 'Formateur malade');
    $b = plSeance($module, '2026-09-08');
    $this->actingAs(plManager())->post("/sessions/{$session->id}/seances/batch", ['seance_ids' => [$b->id, $a->id], 'apply_trainer' => true, 'trainer_id' => ''])
        ->assertStatus(409);
});

it('cancels several séances with one reason, all or nothing', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $module = plModule($session);
    $a = plSeance($module, '2026-09-07');
    $b = plSeance($module, '2026-10-05');
    $url = "/sessions/{$session->id}/seances/batch-cancel";

    $this->actingAs(plManager())->post($url, ['seance_ids' => [$a->id, $b->id], 'reason' => ''])
        ->assertSessionHasErrors(['reason' => 'Indiquez le motif de l\'annulation.']);

    app(Closures::class)->close($session, '2026-10');
    $this->actingAs(plManager())->post($url, ['seance_ids' => [$a->id, $b->id], 'reason' => 'Grève'])->assertStatus(409);
    expect($a->refresh()->status)->toBe(Seance::PLANNED);
    app(Closures::class)->reopen($session, '2026-10', 'Erreur');

    $this->actingAs(plManager())->post($url, ['seance_ids' => [$a->id, $b->id], 'reason' => ' Grève '])
        ->assertRedirect("/sessions/{$session->id}")->assertSessionHas('status', '2 séances annulées.');
    expect($a->refresh()->status)->toBe(Seance::CANCELLED)->and($a->cancel_reason)->toBe('Grève')->and($b->refresh()->status)->toBe(Seance::CANCELLED);
});

it('needs sessions.seances.manage on the session', function () {
    $session = plSession();
    $a = plSeance(plModule($session), '2026-09-07');
    $viewer = l2User([Permissions::SESSIONS_VIEW => null, Permissions::SEANCES_VIEW => null]);
    $own = l2User([Permissions::SESSIONS_VIEW => null, Permissions::SEANCES_VIEW => null, Permissions::SEANCES_MANAGE => AccessScope::Own]);

    $this->actingAs($viewer)->post("/sessions/{$session->id}/seances/batch", ['seance_ids' => [$a->id], 'apply_trainer' => true])->assertForbidden();
    $this->actingAs($own)->post("/sessions/{$session->id}/seances/batch-cancel", ['seance_ids' => [$a->id], 'reason' => 'x'])->assertForbidden();
    expect($a->refresh()->status)->toBe(Seance::PLANNED);
});
