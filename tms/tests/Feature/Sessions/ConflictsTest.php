<?php

use App\Modules\Sessions\Planning\Conflict;
use App\Modules\Sessions\Planning\Conflicts;
use App\Modules\Sessions\Planning\PlacementPeriods;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
});

/**
 * @param  list<Conflict>  $conflicts
 * @return list<string>
 */
function types(array $conflicts): array
{
    return array_values(array_unique(array_map(fn (Conflict $c) => $c->type, $conflicts)));
}

it('finds nothing on a free slot', function () {
    $module = plModule(plSession());

    expect(Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '09:00', 'end' => '12:30']))->toBe([]);
});

it('warns of a trainer already on another overlapping séance', function () {
    $trainer = plTrainer();
    $other = plModule(plSession());
    plSeance($other, '2026-09-02', '09:00', '12:00', ['trainer_id' => $trainer->id]);
    $module = plModule(plSession());

    $conflicts = Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '11:00', 'end' => '13:00', 'trainer_id' => $trainer->id]);

    expect(types($conflicts))->toBe(['trainer_busy'])
        ->and($conflicts[0]->message)->toStartWith('Paul Martin a déjà une séance le 02/09/2026 de 09:00 à 12:00 (')
        // Back to back is no overlap; a cancelled séance frees the slot.
        ->and(Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '12:00', 'end' => '13:00', 'trainer_id' => $trainer->id]))->toBe([]);
    Seance::query()->update(['status' => 'cancelled', 'cancel_reason' => 'Grève']);
    expect(Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '11:00', 'end' => '13:00', 'trainer_id' => $trainer->id]))->toBe([]);
});

it('warns of an unavailable trainer, by Paris time', function () {
    $trainer = plTrainer();
    Trainers::addUnavailability($trainer, CarbonImmutable::parse('2026-09-02 13:00', 'Europe/Paris'), CarbonImmutable::parse('2026-09-02 18:00', 'Europe/Paris'), 'Rendez-vous médical');
    $module = plModule(plSession());

    $conflicts = Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '13:30', 'end' => '17:00', 'trainer_id' => $trainer->id]);

    expect(types($conflicts))->toBe(['trainer_unavailable'])
        ->and($conflicts[0]->message)->toBe('Paul Martin est indisponible le 02/09/2026 sur ce créneau.')
        ->and($conflicts[0]->message)->not->toContain('médical')
        ->and(Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '09:00', 'end' => '12:30', 'trainer_id' => $trainer->id]))->toBe([]);
});

it('warns of a room already taken and of a room too small', function () {
    $room = plRoom('Salle Bocuse', 1);
    plSeance(plModule(plSession()), '2026-09-02', '09:00', '12:00', ['room_id' => $room->id]);
    $session = plSession();
    $module = plModule($session);
    $dossier = l2Dossier();
    l2Enrol($dossier, $session, makePerson('Durand'));
    l2Enrol($dossier, $session, makePerson('Petit'));

    $conflicts = Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '10:00', 'end' => '11:00', 'room_id' => $room->id]);

    expect(types($conflicts))->toBe(['room_busy', 'room_capacity'])
        ->and($conflicts[0]->message)->toStartWith('La salle « Salle Bocuse » est déjà occupée le 02/09/2026 de 09:00 à 12:00')
        ->and($conflicts[1]->message)->toBe('La salle « Salle Bocuse » a 1 place pour 2 stagiaires inscrits.');
});

it('warns of a séance on a public holiday or during a closure', function () {
    $module = plModule(plSession('2026-07-01', '2026-08-31'));
    ClosurePeriod::query()->create(['starts_on' => '2026-08-03', 'ends_on' => '2026-08-21', 'label' => 'Congés d\'été']);

    $holiday = Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-07-14', 'start' => '09:00', 'end' => '12:00']);
    $closure = Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-08-10', 'start' => '09:00', 'end' => '12:00']);

    expect(types($holiday))->toBe(['holiday'])
        ->and($holiday[0]->message)->toBe('Le 14/07/2026 est férié (Fête nationale).')
        ->and(types($closure))->toBe(['closure'])
        ->and($closure[0]->message)->toBe('Le 10/08/2026 tombe pendant la fermeture « Congés d\'été ».');
});

it('warns of a séance during the common placement, or during the own placement of a trainee without excluding it', function () {
    $session = plSession();
    $module = plModule($session);
    $dossier = l2Dossier();
    $durand = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    $period = PlacementPeriods::save($session, ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage en entreprise']);
    PlacementPeriods::setHost($period, $durand, ['organisation_id' => null, 'starts_on' => '2026-09-28', 'ends_on' => '2026-09-30']);
    $petit = l2Enrol($dossier, $session, makePerson('Petit'));

    $common = Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-22', 'start' => '09:00', 'end' => '12:00']);
    $own = Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-29', 'start' => '09:00', 'end' => '12:00']);

    // Durand has her own dates: the common ones only concern Petit.
    expect(types($common))->toBe(['placement'])
        ->and($common[0]->message)->toBe('Le 22/09/2026 tombe pendant le stage « Stage en entreprise » de 1 stagiaire.')
        ->and(types($own))->toBe(['placement'])
        ->and($own[0]->message)->toBe('Stage de Léa Durand le 29/09/2026 (« Stage en entreprise ») : absence prévue à cette séance.')
        ->and($petit->id)->not->toBe($durand->id);

    // A séance is still created there.
    expect(plSeance($module, '2026-09-29')->exists)->toBeTrue();
});

it('warns of a trainee enrolled on another overlapping séance', function () {
    $person = makePerson('Durand', ['first_name' => 'Léa']);
    $dossier = l2Dossier();
    $other = plSession();
    l2Enrol($dossier, $other, $person);
    plSeance(plModule($other), '2026-09-02', '09:00', '12:00');
    $session = plSession();
    l2Enrol(l2Dossier(), $session, $person);
    $module = plModule($session);

    $conflicts = Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '11:00', 'end' => '12:30']);

    expect(types($conflicts))->toBe(['trainee_busy'])
        ->and($conflicts[0]->message)->toStartWith('Léa Durand a déjà une séance le 02/09/2026 de 09:00 à 12:00 (');
});

it('checks a stored séance, leaving itself out, and never blocks its saving', function () {
    $trainer = plTrainer();
    $module = plModule(plSession('2026-07-01', '2026-07-31'));
    $first = plSeance($module, '2026-07-14', '09:00', '12:00', ['trainer_id' => $trainer->id]);
    $second = plSeance($module, '2026-07-14', '10:00', '11:00', ['trainer_id' => $trainer->id]);

    expect(types(Conflicts::for($first)))->toBe(['holiday', 'seance_overlap', 'trainer_busy'])
        ->and(Seance::query()->count())->toBe(2)
        ->and(types(Conflicts::for($second)))->toBe(['holiday', 'seance_overlap', 'trainer_busy']);
});

it('sends the alerts of a séance saved through the form as a warning, never as an error', function () {
    $session = plSession('2026-07-01', '2026-07-31');
    $module = plModule($session);

    $this->actingAs(plManager())->post("/sessions/{$session->id}/seances", [
        'session_module_id' => $module->id, 'date' => '2026-07-14', 'starts_at' => '09:00', 'ends_at' => '12:00', 'break_minutes' => 0,
    ])->assertRedirect("/sessions/{$session->id}")->assertSessionHasNoErrors()
        ->assertSessionHas('warning', 'Le 14/07/2026 est férié (Fête nationale).');

    expect(Seance::query()->count())->toBe(1);
});

it('answers the alerts of a séance being typed (form check)', function () {
    $session = plSession('2026-07-01', '2026-07-31');
    $module = plModule($session);
    $seance = plSeance($module, '2026-07-15');

    $this->actingAs(plManager())->postJson("/sessions/{$session->id}/seances/check", [
        'session_module_id' => $module->id, 'date' => '2026-07-14', 'starts_at' => '09:00', 'ends_at' => '12:00', 'seance_id' => $seance->id,
    ])->assertOk()->assertJsonPath('alerts.0.type', 'holiday')->assertJsonPath('alerts.0.message', 'Le 14/07/2026 est férié (Fête nationale).');
});

it('warns of another séance of the same session on an overlapping slot', function () {
    $module = plModule(plSession());
    plSeance($module, '2026-09-02', '09:00', '12:30');

    $conflicts = Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '09:30', 'end' => '12:00']);

    expect(types($conflicts))->toBe(['seance_overlap'])
        ->and($conflicts[0]->message)->toBe('Une autre séance de la session a lieu le 02/09/2026 de 09:00 à 12:30 (HACCP 3 jours).')
        ->and(Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-02', 'start' => '13:30', 'end' => '17:00']))->toBe([]);
});

it('warns of the own placement dates of a trainee set before the common period', function () {
    $session = plSession();
    $module = plModule($session);
    $durand = l2Enrol(l2Dossier(), $session, makePerson('Durand', ['first_name' => 'Léa']));
    $period = PlacementPeriods::save($session, ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage']);
    PlacementPeriods::setHost($period, $durand, ['organisation_id' => null, 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-09']);

    expect(types(Conflicts::for(['session_module_id' => $module->id, 'date' => '2026-09-08', 'start' => '09:00', 'end' => '12:00'])))->toBe(['placement']);
});

it('checks a séance being changed against its current inactive trainer and room without refusing them', function () {
    $session = plSession();
    $module = plModule($session);
    $trainer = plTrainer();
    $room = plRoom();
    $seance = plSeance($module, '2026-09-02', extra: ['trainer_id' => $trainer->id, 'room_id' => $room->id]);
    $trainer->update(['active' => false]);
    $room->update(['active' => false]);

    $this->actingAs(plManager())->postJson("/sessions/{$session->id}/seances/check", [
        'session_module_id' => $module->id, 'date' => '2026-09-03', 'starts_at' => '09:00', 'ends_at' => '12:00',
        'trainer_id' => $trainer->id, 'room_id' => $room->id, 'seance_id' => $seance->id,
    ])->assertOk()->assertJsonPath('alerts', []);
    // Without the séance, an inactive trainer is refused.
    $this->actingAs(plManager())->postJson("/sessions/{$session->id}/seances/check", [
        'session_module_id' => $module->id, 'date' => '2026-09-03', 'starts_at' => '09:00', 'ends_at' => '12:00', 'trainer_id' => $trainer->id,
    ])->assertStatus(422);
});
