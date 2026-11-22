<?php

use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Automation\DayBeforeMail;
use App\Modules\Sessions\Automation\DayBeforeReminders;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\Trainers;
use App\Modules\Sessions\Trainers\TrainerStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Support/lot2a.php';
require_once __DIR__.'/../Support/planning.php';
require_once __DIR__.'/../Support/attendance.php';

beforeEach(function () {
    plSetUp();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-12-07 07:15', 'Europe/Paris'));
});

/**
 * A confirmed session with two séances on 08/12 (same trainer), Léa and Paul enrolled with an e-mail.
 *
 * @return array{session: TrainingSession, lea: Enrolment, paul: Enrolment, morning: Seance}
 */
function dbSession(string $status = 'confirmed'): array
{
    $trainer = Trainer::query()->first() ?? Trainers::declarePerson('Martin', 'Marie', 'marie.martin@example.test', TrainerStatus::Freelance);
    $session = plSession('2026-12-08', '2026-12-08', ['status' => $status]);
    $module = plModule($session);
    $morning = plSeance($module, '2026-12-08', '09:00', '12:30', ['trainer_id' => $trainer->id, 'place_text' => 'Salle B, Nantes']);
    plSeance($module, '2026-12-08', '13:30', '17:00', ['trainer_id' => $trainer->id, 'place_text' => 'Salle B, Nantes']);
    $lea = l2Enrol(l2Dossier(), $session, makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea@example.test']));
    $paul = l2Enrol(l2Dossier(), $session, makePerson('Petit', ['first_name' => 'Paul', 'email' => 'paul@example.test']));

    return ['session' => $session, 'lea' => $lea, 'paul' => $paul, 'morning' => $morning];
}

function dbRun(): int
{
    return app(DayBeforeReminders::class)->run(CarbonImmutable::now('Europe/Paris'));
}

it('reminds each expected trainee and the trainer of tomorrow', function () {
    dbSession();

    expect(dbRun())->toBe(3);
    Mail::assertSentCount(3);
    Mail::assertSent(DayBeforeMail::class, fn (DayBeforeMail $m) => $m->hasTo('lea@example.test')
        && str_contains($m->text, '09:00') && str_contains($m->text, 'Salle B, Nantes')
        && ! str_contains($m->text, 'Petit') && ! str_contains($m->text, '€'));
    Mail::assertSent(DayBeforeMail::class, fn (DayBeforeMail $m) => $m->hasTo('marie.martin@example.test')
        && str_contains($m->text, '09:00') && str_contains($m->text, '13:30'));
});

it('never reminds twice the same day', function () {
    dbSession();
    dbRun();

    expect(dbRun())->toBe(0);
    Mail::assertSentCount(3);
});

it('skips planned sessions, cancelled séances, cancelled, trashed or anonymised trainees', function () {
    dbSession('planned');
    $x = dbSession();
    $x['lea']->forceFill(['status' => EnrolmentStatus::Cancelled->value])->save();
    Person::query()->whereKey($x['paul']->person_id)->firstOrFail()->delete();
    Seance::query()->where('training_session_id', $x['session']->id)->update(['status' => Seance::CANCELLED]);

    expect(dbRun())->toBe(0);
    Mail::assertNothingSent();
});

it('does nothing when day_before is off', function () {
    dbSession();
    AutomationSettings::save(['day_before' => false]);

    expect(dbRun())->toBe(0);
});
