<?php

/*
 * Helpers of the planning tests (séances, stages, conflicts), included with
 * require_once after Support/lot2a.php.
 */

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\Trainers;
use App\Modules\Sessions\Trainers\TrainerStatus;

function plSetUp(?Tenant $tenant = null): void
{
    inTenant($tenant);
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
}

/**
 * An inter session of the default training (one module of 21 h), from $from to $to.
 *
 * @param  array<string, mixed>  $overrides
 */
function plSession(string $from = '2026-09-01', string $to = '2026-09-30', array $overrides = []): TrainingSession
{
    return l2Session(null, ['starts_on' => $from, 'ends_on' => $to, ...$overrides]);
}

/**
 * The (first) module of the session, dated in séances.
 */
function plModule(TrainingSession $session): SessionModule
{
    $module = SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->firstOrFail();
    app(SessionModules::class)->switchMode($module, 'seances');

    return $module->refresh();
}

function plTrainer(string $lastName = 'Martin', ?string $email = null): Trainer
{
    return Trainers::declarePerson($lastName, 'Paul', $email, TrainerStatus::Freelance);
}

function plRoom(string $name = 'Salle A', ?int $capacity = null): Room
{
    return Room::query()->create(['name' => $name, 'capacity' => $capacity]);
}

/**
 * Who plans the séances of every session (and sees them).
 */
function plManager(): User
{
    return userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SESSIONS_UPDATE, Permissions::SEANCES_VIEW, Permissions::SEANCES_MANAGE]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function plSeance(SessionModule $module, string $date, string $start = '09:00', string $end = '12:30', array $extra = []): Seance
{
    $session = TrainingSession::query()->findOrFail($module->training_session_id);

    return app(Seances::class)->create($session, [
        'session_module_id' => $module->id, 'date' => $date, 'starts_at' => $start, 'ends_at' => $end, ...$extra,
    ]);
}
