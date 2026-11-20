<?php

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\FollowUps\FollowUpReminder;
use App\Modules\Sessions\FollowUps\TrainerFollowUps;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainers;
use App\Modules\Sessions\Trainers\TrainerStatus;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-08-10 10:00', 'Europe/Paris'));
});

/**
 * A session from 17 to 20/08/2026 with Léa enrolled; $hours: trainer user => number of 3.5 h séances.
 *
 * @param  array<string, int>  $seancesByTrainer  user name => séances
 * @return array{session: TrainingSession, lea: Enrolment, users: array<string, User>}
 */
function tfuSession(array $seancesByTrainer = ['Bernard' => 1, 'Aubert' => 3], ?string $responsibleUserId = null): array
{
    $session = plSession('2026-08-17', '2026-08-20', ['responsible_user_id' => $responsibleUserId]);
    $module = plModule($session);
    $days = ['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20'];
    $users = [];
    $i = 0;
    foreach ($seancesByTrainer as $name => $count) {
        $user = admin();
        $user->forceFill(['name' => $name])->save();
        $trainer = Trainers::declareUser($user, TrainerStatus::Employee);
        $users[$name] = $user;
        for ($n = 0; $n < $count; $n++) {
            plSeance($module, $days[$i % 4], $i < 4 ? '09:00' : '13:30', $i < 4 ? '12:30' : '17:00', ['trainer_id' => $trainer->id]);
            $i++;
        }
    }
    $lea = l2Enrol(l2Dossier(), $session, makePerson('Durand', ['first_name' => 'Léa']));

    return ['session' => $session->refresh(), 'lea' => $lea, 'users' => $users];
}

function tfuRun(string $day): int
{
    test()->travelTo(CarbonImmutable::parse($day.' 06:30', 'Europe/Paris'));

    return app(TrainerFollowUps::class)->run(CarbonImmutable::parse($day, 'Europe/Paris'));
}

it('plans one call per trainee three months after the end, to the trainer with an account who taught the most hours', function () {
    $x = tfuSession();

    expect(tfuRun('2026-11-19'))->toBe(0)
        ->and(tfuRun('2026-11-20'))->toBe(1);

    $call = Activity::query()->sole();
    expect($call->person_id)->toBe($x['lea']->person_id)
        ->and($call->responsible_user_id)->toBe($x['users']['Aubert']->id)
        ->and($call->subject)->toBe('Suivi à 3 mois : Léa Durand, HACCP 3 jours ('.$x['session']->number.')')
        ->and($call->due_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-11-20 09:00')
        ->and(FollowUpReminder::query()->sole()->only(['kind', 'subject_id', 'months', 'outcome']))
        ->toBe(['kind' => 'trainer', 'subject_id' => $x['lea']->id, 'months' => 3, 'outcome' => 'created']);

    expect(tfuRun('2026-11-21'))->toBe(0)->and(Activity::query()->count())->toBe(1);
});

it('breaks a tie of hours by the trainer name', function () {
    $x = tfuSession(['Bernard' => 2, 'Aubert' => 2]);
    tfuRun('2026-11-20');

    expect(Activity::query()->sole()->responsible_user_id)->toBe($x['users']['Aubert']->id);
});

it('falls back on the session responsible, then the dossier responsible, when no trainer has an account', function () {
    $responsible = admin();
    $x = tfuSession([], $responsible->id);
    $freelance = plTrainer('Martin');
    plSeance(plModule($x['session']), '2026-08-17', extra: ['trainer_id' => $freelance->id]);
    tfuRun('2026-11-20');
    expect(Activity::query()->sole()->responsible_user_id)->toBe($responsible->id);

    // No session responsible: the dossier's.
    Activity::query()->delete();
    FollowUpReminder::query()->delete();
    $x['session']->refresh()->forceFill(['responsible_user_id' => null])->save();
    $dossierResponsible = admin();
    $x['lea']->dossier->forceFill(['responsible_user_id' => $dossierResponsible->id])->save();
    tfuRun('2026-11-20');
    expect(Activity::query()->sole()->responsible_user_id)->toBe($dossierResponsible->id);
});

it('skips cancelled sessions, abandoned or cancelled enrolments and anonymised people', function () {
    $x = tfuSession();
    $tom = l2Enrol($x['lea']->dossier, $x['session'], makePerson('Petit', ['first_name' => 'Tom']));
    $ana = l2Enrol($x['lea']->dossier, $x['session'], makePerson('Roux', ['first_name' => 'Ana']));
    app(Enrolments::class)->abandon($tom, CarbonImmutable::parse('2026-08-18'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);
    app(Anonymiser::class)->anonymise(Person::query()->findOrFail($ana->person_id), 'request');

    expect(tfuRun('2026-11-20'))->toBe(1)
        ->and(Activity::query()->sole()->person_id)->toBe($x['lea']->person_id)
        ->and(FollowUpReminder::query()->where('subject_id', $ana->id)->value('outcome'))->toBe('skipped')
        ->and(FollowUpReminder::query()->where('subject_id', $tom->id)->exists())->toBeFalse();
});

it('never plans a due date older than 30 days on its first run', function () {
    tfuSession();

    expect(tfuRun('2027-01-10'))->toBe(0)
        ->and(Activity::query()->count())->toBe(0)
        ->and(FollowUpReminder::query()->count())->toBe(0);
});

it('catches up a due date missed for a few days', function () {
    tfuSession();

    expect(tfuRun('2026-11-25'))->toBe(1)
        ->and(Activity::query()->sole()->due_at->setTimezone('Europe/Paris')->format('Y-m-d'))->toBe('2026-11-20');
});

it('is disabled at 0 months', function () {
    tfuSession();
    tenant()->forceFill(['settings' => ['sessions' => ['trainer_follow_up_months' => 0]]])->save();

    expect(tfuRun('2026-11-20'))->toBe(0)->and(Activity::query()->count())->toBe(0);
});

it('runs every morning through tms:sessions:follow-ups', function () {
    tfuSession();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 06:30', 'Europe/Paris'));

    $this->artisan('tms:sessions:follow-ups')->assertSuccessful();
    expect(Activity::query()->count())->toBe(1);
});
