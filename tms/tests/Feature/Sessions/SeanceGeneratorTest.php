<?php

use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\GenerationRequest;
use App\Modules\Sessions\Planning\PlacementPeriods;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\SeanceGenerator;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
});

/**
 * Mon.–Thu., 9:00–12:30 and 13:30–17:00 (no break inside a slot).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function genInput(string $from = '2026-09-01', string $to = '2026-09-30', array $overrides = []): array
{
    return [
        'weekdays' => [1, 2, 3, 4],
        'slots' => [
            ['start' => '09:00', 'end' => '12:30', 'break_minutes' => 0],
            ['start' => '13:30', 'end' => '17:00', 'break_minutes' => 0],
        ],
        'from' => $from, 'to' => $to, 'trainer_id' => null, 'room_id' => null, 'place_text' => null,
        ...$overrides,
    ];
}

function genRequest(SessionModule $module, array $input): GenerationRequest
{
    return GenerationRequest::fromInput($input, $module);
}

/**
 * September 2026, Mon.–Thu.: 18 days; closed on the 14th and 15th, common
 * placement from the 21st to the 25th: 12 days × 2 slots of 3 h 30.
 */
function septemberSetUp(): array
{
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    ClosurePeriod::query()->create(['starts_on' => '2026-09-14', 'ends_on' => '2026-09-15', 'label' => 'Inventaire']);
    PlacementPeriods::save($session, ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage en entreprise']);

    return [$session, $module];
}

it('previews the séances of a regular timetable without holidays, closures nor common placement dates, writing nothing', function () {
    [, $module] = septemberSetUp();

    $preview = app(SeanceGenerator::class)->preview($module, genRequest($module, genInput()));

    $kept = array_values(array_filter($preview->seances, fn (array $s) => $s['skipped_reason'] === null));
    $skipped = array_values(array_filter($preview->seances, fn (array $s) => $s['skipped_reason'] !== null));
    expect($kept)->toHaveCount(24)
        ->and(array_unique(array_column($kept, 'date')))->not->toContain('2026-09-14', '2026-09-15', '2026-09-21', '2026-09-24')
        ->and($kept[0])->toBe(['date' => '2026-09-01', 'start' => '09:00', 'end' => '12:30', 'minutes' => 210, 'skipped_reason' => null])
        ->and($kept[1])->toMatchArray(['date' => '2026-09-01', 'start' => '13:30', 'end' => '17:00', 'minutes' => 210])
        ->and($skipped)->toHaveCount(12)
        ->and(collect($skipped)->firstWhere('date', '2026-09-14')['skipped_reason'])->toBe('Fermeture : Inventaire')
        ->and(collect($skipped)->firstWhere('date', '2026-09-22')['skipped_reason'])->toBe('Stage : Stage en entreprise')
        ->and($preview->total_minutes)->toBe(24 * 210)
        // The module of the default training: 21 h planned.
        ->and($preview->planned_minutes)->toBe(1260)
        ->and($preview->existing_minutes)->toBe(0)
        ->and($preview->warning)->toBe('84 h planifiées pour 21 h prévues sur ce module.')
        ->and(Seance::query()->count())->toBe(0);
});

it('skips the public holidays, named', function () {
    $session = plSession('2026-11-02', '2026-11-30');
    $module = plModule($session);

    $preview = app(SeanceGenerator::class)->preview($module, genRequest($module, genInput('2026-11-09', '2026-11-12')));

    expect(collect($preview->seances)->where('date', '2026-11-11')->pluck('skipped_reason')->unique()->all())->toBe(['Férié : Armistice 1918'])
        ->and(collect($preview->seances)->whereNull('skipped_reason')->pluck('date')->unique()->values()->all())
        ->toBe(['2026-11-09', '2026-11-10', '2026-11-12']);
});

it('generates the previewed séances with their trainer, room and place, totals in whole minutes', function () {
    [$session, $module] = septemberSetUp();
    $trainer = plTrainer();
    $room = plRoom();

    $created = app(SeanceGenerator::class)->generate($module, genRequest($module, genInput(overrides: [
        'trainer_id' => $trainer->id, 'room_id' => $room->id, 'place_text' => null,
    ])));

    expect($created)->toHaveCount(24)
        ->and(Seance::query()->where('session_module_id', $module->id)->count())->toBe(24)
        ->and((int) Seance::query()->sum('duration_minutes'))->toBe(5040)
        ->and(Seance::query()->where('trainer_id', $trainer->id)->where('room_id', $room->id)->where('training_session_id', $session->id)->count())->toBe(24)
        ->and(Seance::query()->whereIn('date', ['2026-09-14', '2026-09-15', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24'])->count())->toBe(0);
});

it('never duplicates nor changes an existing séance when generating again over an overlapping period (Review Focus 1)', function () {
    [, $module] = septemberSetUp();
    app(SeanceGenerator::class)->generate($module, genRequest($module, genInput('2026-09-01', '2026-09-10')));
    $before = Seance::query()->orderBy('date')->orderBy('starts_at')->get();
    expect($before)->toHaveCount(14);
    // A séance changed by hand meanwhile keeps its change.
    $changed = $before->first();
    $changed->forceFill(['note' => 'Déplacée en salle B'])->save();
    $this->travel(1)->minutes();

    $preview = app(SeanceGenerator::class)->preview($module, genRequest($module, genInput('2026-09-01', '2026-09-30')));
    expect(collect($preview->seances)->where('date', '2026-09-03')->pluck('skipped_reason')->unique()->all())->toBe(['Séance déjà planifiée'])
        ->and($preview->existing_minutes)->toBe(14 * 210)
        ->and($preview->total_minutes)->toBe(10 * 210);

    $created = app(SeanceGenerator::class)->generate($module, genRequest($module, genInput('2026-09-01', '2026-09-30')));

    expect($created)->toHaveCount(10)
        ->and(Seance::query()->count())->toBe(24)
        ->and(Seance::query()->selectRaw('count(*) as n')->groupBy('session_module_id', 'date', 'starts_at')->havingRaw('count(*) > 1')->count())->toBe(0);
    foreach ($before as $old) {
        $now = Seance::query()->findOrFail($old->id);
        expect($now->updated_at->equalTo($old->id === $changed->id ? $changed->updated_at : $old->updated_at))->toBeTrue();
    }
    expect(Seance::query()->findOrFail($changed->id)->note)->toBe('Déplacée en salle B');
});

it('keeps 9:00–17:00 Paris time and its duration after the change to winter time (Review Focus 5)', function () {
    $session = plSession('2026-10-19', '2026-10-30');
    $module = plModule($session);

    app(SeanceGenerator::class)->generate($module, genRequest($module, genInput('2026-10-19', '2026-10-30', [
        'weekdays' => [1, 5],
        'slots' => [['start' => '09:00', 'end' => '17:00', 'break_minutes' => 60]],
    ])));

    $seances = Seance::query()->orderBy('date')->get();
    expect($seances->pluck('date')->map(fn (CarbonImmutable $d) => $d->toDateString())->all())->toBe(['2026-10-19', '2026-10-23', '2026-10-26', '2026-10-30']);
    foreach ($seances as $seance) {
        expect($seance->start())->toBe('09:00')->and($seance->end())->toBe('17:00')->and($seance->duration_minutes)->toBe(420);
    }
    // Summer time on the 23rd (UTC+2), winter time on the 26th (UTC+1).
    expect($seances[1]->startsAtInstant()->utc()->format('Y-m-d H:i'))->toBe('2026-10-23 07:00')
        ->and($seances[2]->startsAtInstant()->utc()->format('Y-m-d H:i'))->toBe('2026-10-26 08:00')
        ->and($seances[2]->endsAtInstant()->utc()->format('Y-m-d H:i'))->toBe('2026-10-26 16:00');
});

it('refuses a module dated as an interval (409)', function () {
    $session = plSession();
    $module = SessionModule::query()->where('training_session_id', $session->id)->firstOrFail();

    $this->actingAs(plManager())->postJson("/sessions/{$session->id}/modules/{$module->id}/seances/preview", genInput())
        ->assertStatus(409)->assertJson(['message' => 'Ce module est daté par un intervalle : passez-le en séances pour générer ses séances.']);
    expect(fn () => app(SeanceGenerator::class)->generate($module, GenerationRequest::fromInput(genInput(), $module)))
        ->toThrow(HttpException::class);
});

it('validates the assistant in French', function () {
    $session = plSession();
    $module = plModule($session);

    $errors = fn (array $input) => (function () use ($input, $module) {
        try {
            GenerationRequest::fromInput($input, $module);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    })();

    expect($errors(genInput(overrides: ['weekdays' => []])))->toHaveKey('weekdays')
        ->and($errors(genInput(overrides: ['weekdays' => [8]]))['weekdays.0'] ?? $errors(genInput(overrides: ['weekdays' => [8]]))['weekdays'] ?? null)->not->toBeNull()
        ->and($errors(genInput(overrides: ['slots' => []]))['slots'])->toBe(['Ajoutez au moins un créneau.'])
        ->and($errors(genInput(overrides: ['slots' => [['start' => '12:00', 'end' => '09:00', 'break_minutes' => 0]]]))['slots.0.end'])->toBe(['La fin du créneau doit suivre son début.'])
        ->and($errors(genInput(overrides: ['slots' => [['start' => '09:00', 'end' => '10:00', 'break_minutes' => 60]]]))['slots.0.break_minutes'])->toBe(['La pause doit être plus courte que le créneau.'])
        ->and($errors(genInput(overrides: ['slots' => [['start' => '09:00', 'end' => '12:00', 'break_minutes' => 0], ['start' => '11:00', 'end' => '13:00', 'break_minutes' => 0]]]))['slots.1.start'])->toBe(['Les créneaux ne doivent pas se chevaucher.'])
        ->and($errors(genInput('2026-08-25', '2026-09-30'))['from'])->toBe(['La période doit rester dans les dates de la session (du 01/09/2026 au 30/09/2026).'])
        ->and($errors(genInput('2026-09-20', '2026-09-10'))['to'])->toBe(['La fin de la période doit suivre son début.'])
        ->and($errors(genInput(overrides: ['trainer_id' => '0190d6c5-0000-7000-8000-000000000000']))['trainer_id'])->toBe(['Formateur introuvable.'])
        ->and($errors(genInput(overrides: ['room_id' => '0190d6c5-0000-7000-8000-000000000000']))['room_id'])->toBe(['Salle introuvable.']);
});

it('previews through the route as JSON with the alerts, and generates through the route', function () {
    [$session, $module] = septemberSetUp();
    $room = plRoom('Atelier', 1);
    // Two trainees for a room of one place: an alert, never a refusal.
    $dossier = l2Dossier();
    l2Enrol($dossier, $session, makePerson('Durand'));
    l2Enrol($dossier, $session, makePerson('Petit'));
    $manager = plManager();

    $this->actingAs($manager)->postJson("/sessions/{$session->id}/modules/{$module->id}/seances/preview", genInput('2026-09-01', '2026-09-03', ['room_id' => $room->id]))
        ->assertOk()
        ->assertJsonPath('total_minutes', 6 * 210)
        ->assertJsonPath('planned', '21 h')
        ->assertJsonPath('total', '21 h')
        ->assertJsonPath('alerts.0.type', 'room_capacity')
        ->assertJsonCount(6, 'seances');
    expect(Seance::query()->count())->toBe(0);

    $this->actingAs($manager)->post("/sessions/{$session->id}/modules/{$module->id}/seances/generate", genInput('2026-09-01', '2026-09-03', ['room_id' => $room->id]))
        ->assertRedirect("/sessions/{$session->id}")
        ->assertSessionHas('status', '6 séances créées.');
    expect(Seance::query()->count())->toBe(6);

    $other = TrainingSession::query()->whereKeyNot($session->id)->first() ?? plSession();
    $this->actingAs($manager)->postJson("/sessions/{$other->id}/modules/{$module->id}/seances/preview", genInput())->assertNotFound();
    $this->actingAs(userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW]))
        ->postJson("/sessions/{$session->id}/modules/{$module->id}/seances/preview", genInput())->assertForbidden();
});

it('never creates a séance overlapping a planned séance of the session, whatever its module or slot', function () {
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    $other = SessionModule::query()->create(['training_session_id' => $session->id, 'position' => 2, 'title' => 'Pratique', 'planned_minutes' => 600, 'dating_mode' => 'seances']);
    app(SeanceGenerator::class)->generate($module, genRequest($module, genInput('2026-09-01', '2026-09-03', ['slots' => [['start' => '09:00', 'end' => '12:30', 'break_minutes' => 0]]])));
    // A cancelled séance frees its slot.
    $cancelled = Seance::query()->where('date', '2026-09-03')->sole();
    app(Seances::class)->cancel($cancelled, 'Grève');

    $shifted = genInput('2026-09-01', '2026-09-03', ['slots' => [['start' => '09:30', 'end' => '12:00', 'break_minutes' => 0], ['start' => '13:30', 'end' => '17:00', 'break_minutes' => 0]]]);
    $preview = app(SeanceGenerator::class)->preview($module, genRequest($module, $shifted));
    expect(collect($preview->seances)->where('start', '09:30')->pluck('skipped_reason', 'date')->all())->toBe([
        '2026-09-01' => 'Séance déjà planifiée sur ce créneau',
        '2026-09-02' => 'Séance déjà planifiée sur ce créneau',
        '2026-09-03' => null,
    ]);

    // Another module of the same session neither.
    $created = app(SeanceGenerator::class)->generate($other, genRequest($other, $shifted));
    expect(collect($created)->map(fn (Seance $s) => $s->date->toDateString().' '.$s->start())->all())
        ->toBe(['2026-09-01 13:30', '2026-09-02 13:30', '2026-09-03 09:30', '2026-09-03 13:30']);
});

// ---------------------------------------------------------------- end estimated from the planned hours

it('stops at the planned hours of the module and gives the estimated end', function () {
    [, $module] = septemberSetUp();
    $module->forceFill(['planned_minutes' => 2100])->save(); // 35 h = 5 days of 7 h

    $preview = app(SeanceGenerator::class)->preview($module, genRequest($module, genInput('2026-09-01', '', ['until' => 'hours'])));

    expect($preview->kept())->toHaveCount(10)
        ->and($preview->total_minutes)->toBe(2100)
        ->and($preview->toArray()['estimated_end'])->toBe('2026-09-08')
        ->and($preview->toArray()['reached'])->toBeTrue()
        ->and($preview->warning)->toBeNull();
    // The closure (14–15) and the placement (21–25) are skipped on the way, never counted.
    $module->forceFill(['planned_minutes' => 2100 + 420 * 3])->save();
    $later = app(SeanceGenerator::class)->preview($module, genRequest($module, genInput('2026-09-01', '', ['until' => 'hours'])));
    expect($later->toArray()['estimated_end'])->toBe('2026-09-16');
});

it('counts the séances already planned and generates only the remaining hours', function () {
    [, $module] = septemberSetUp();
    $module->forceFill(['planned_minutes' => 2100])->save();
    plSeance($module, '2026-09-01', '09:00', '12:30');
    plSeance($module, '2026-09-01', '13:30', '17:00');

    $created = app(SeanceGenerator::class)->generate($module, genRequest($module, genInput('2026-09-01', '', ['until' => 'hours'])));

    expect($created)->toHaveCount(8)
        ->and((int) Seance::query()->where('session_module_id', $module->id)->sum('duration_minutes'))->toBe(2100)
        ->and((string) Seance::query()->max('date'))->toStartWith('2026-09-08');
});

it('says when the planned hours do not fit before the end of the session', function () {
    [, $module] = septemberSetUp();
    $module->forceFill(['planned_minutes' => 12000])->save();

    $preview = app(SeanceGenerator::class)->preview($module, genRequest($module, genInput('2026-09-01', '', ['until' => 'hours'])))->toArray();

    expect($preview['count'])->toBe(24)
        ->and($preview['reached'])->toBeFalse()
        ->and($preview['estimated_end'])->toBe('2026-09-30')
        ->and($preview['warning'])->toContain('planifiées pour 200 h prévues');
});

it('needs planned hours to estimate the end', function () {
    [, $module] = septemberSetUp();
    $module->forceFill(['planned_minutes' => null])->save();

    expect(fn () => genRequest($module, genInput('2026-09-01', '', ['until' => 'hours'])))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['until' => ['Ce module n\'a pas d\'heures prévues : indiquez la date de fin.']]));
});

it('gives a slot its own days of the week: another timetable on Fridays', function () {
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    $input = genInput('2026-09-07', '2026-09-11', ['weekdays' => [1, 2, 3, 4, 5], 'slots' => [
        ['start' => '09:00', 'end' => '12:30', 'break_minutes' => 0, 'days' => [1, 2, 3, 4]],
        ['start' => '13:30', 'end' => '17:00', 'break_minutes' => 0, 'days' => [1, 2, 3, 4]],
        ['start' => '08:30', 'end' => '12:00', 'break_minutes' => 0, 'days' => [5]],
    ]]);

    $kept = array_values(array_filter(app(SeanceGenerator::class)->preview($module, genRequest($module, $input))->seances, fn (array $s) => $s['skipped_reason'] === null));

    // Mon. 7 → Thu. 10: two slots a day; Fri. 11: the morning slot of Friday only.
    expect($kept)->toHaveCount(9)
        ->and(array_values(array_filter($kept, fn (array $s) => $s['date'] === '2026-09-11')))->toBe([
            ['date' => '2026-09-11', 'start' => '08:30', 'end' => '12:00', 'minutes' => 210, 'skipped_reason' => null],
        ]);
});

it('lets two slots overlap when they never share a day, never otherwise', function () {
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);

    $apart = genInput(overrides: ['weekdays' => [1, 5], 'slots' => [
        ['start' => '09:00', 'end' => '12:30', 'break_minutes' => 0, 'days' => [1]],
        ['start' => '08:30', 'end' => '12:00', 'break_minutes' => 0, 'days' => [5]],
    ]]);
    expect(genRequest($module, $apart)->slots)->toHaveCount(2);

    $together = genInput(overrides: ['weekdays' => [1, 5], 'slots' => [
        ['start' => '09:00', 'end' => '12:30', 'break_minutes' => 0, 'days' => [1, 5]],
        ['start' => '08:30', 'end' => '12:00', 'break_minutes' => 0, 'days' => [5]],
    ]]);
    expect(fn () => genRequest($module, $together))->toThrow(fn (ValidationException $e) => expect($e->errors()['slots.1.start'][0])->toBe('Les créneaux ne doivent pas se chevaucher.'));
});

it('replaces the slots of every day by the slots named for a day (the reported bug: « nothing overlaps »)', function () {
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    // The default slots kept for every day, plus another timetable on Fridays.
    $input = genInput('2026-09-07', '2026-09-11', ['weekdays' => [1, 2, 3, 4, 5], 'slots' => [
        ['start' => '09:00', 'end' => '12:30', 'break_minutes' => 0],
        ['start' => '13:30', 'end' => '17:00', 'break_minutes' => 0],
        ['start' => '08:30', 'end' => '12:00', 'break_minutes' => 0, 'days' => [5]],
    ]]);

    $kept = array_values(array_filter(app(SeanceGenerator::class)->preview($module, genRequest($module, $input))->seances, fn (array $s) => $s['skipped_reason'] === null));

    expect($kept)->toHaveCount(9)
        ->and(array_column(array_values(array_filter($kept, fn (array $s) => $s['date'] === '2026-09-11')), 'start'))->toBe(['08:30'])
        ->and(array_column(array_values(array_filter($kept, fn (array $s) => $s['date'] === '2026-09-07')), 'start'))->toBe(['09:00', '13:30']);
});

it('refuses a slot on a day not ticked', function () {
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);

    $input = genInput(overrides: ['weekdays' => [1, 2], 'slots' => [['start' => '09:00', 'end' => '12:00', 'break_minutes' => 0, 'days' => [5]]]]);

    expect(fn () => genRequest($module, $input))->toThrow(fn (ValidationException $e) => expect($e->errors()['slots.0.days'][0])->toBe('Cochez aussi ce jour dans les jours de la semaine.'));
});
