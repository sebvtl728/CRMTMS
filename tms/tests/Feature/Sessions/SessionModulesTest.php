<?php

use App\Modules\Catalogue\Training;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * A training of the catalogue with modules of these hours (decimals, as the catalogue stores them).
 *
 * @param  list<string|null>  $hours
 */
function modTraining(array $hours, string $title = 'Commis de cuisine'): Training
{
    $training = Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '21.00']);
    foreach ($hours as $i => $h) {
        $training->modules()->create(['position' => $i, 'title' => 'Module '.($i + 1), 'duration_hours' => $h,
            'modality_id' => $i === 0 ? crmItem(ReferenceList::TrainingModality, 'FOAD / e-learning')->id : null]);
    }

    return $training;
}

/**
 * @return list<SessionModule>
 */
function modulesOf(TrainingSession $session): array
{
    return SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->get()->all();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function modPayload(array $overrides = []): array
{
    return ['title' => 'Hygiène', 'planned_hours' => '3,5', 'modality_id' => '', 'dating_mode' => 'interval', 'starts_on' => '', 'ends_on' => '', ...$overrides];
}

// ---------------------------------------------------------------- copy from the catalogue

it('copies the modules of the training at creation, in minutes, the session hours their sum', function () {
    $session = l2Session(modTraining(['7.00', '14.00', '3.50']));

    $modules = modulesOf($session);
    expect(array_map(fn (SessionModule $m) => $m->planned_minutes, $modules))->toBe([420, 840, 210])
        ->and(array_map(fn (SessionModule $m) => $m->title, $modules))->toBe(['Module 1', 'Module 2', 'Module 3'])
        ->and(array_map(fn (SessionModule $m) => $m->position, $modules))->toBe([1, 2, 3])
        ->and($modules[0]->modality_id)->toBe(crmItem(ReferenceList::TrainingModality, 'FOAD / e-learning')->id)
        ->and($modules[1]->modality_id)->toBeNull()
        ->and($modules[0]->dating_mode)->toBe('interval')
        ->and($modules[0]->starts_on->toDateString())->toBe('2026-12-07')
        ->and($modules[0]->ends_on->toDateString())->toBe('2026-12-09')
        ->and($session->fresh()->planned_hours)->toBe('24.50');
});

it('gives a training without module one module of its title at the hours of the session', function () {
    $session = l2Session(modTraining([], 'Sauveteur secouriste'), ['planned_hours' => '17,5']);

    $modules = modulesOf($session);
    expect($modules)->toHaveCount(1)
        ->and($modules[0]->title)->toBe('Sauveteur secouriste')
        ->and($modules[0]->planned_minutes)->toBe(1050)
        ->and($modules[0]->modality_id)->toBe($session->modality_id)
        ->and($session->fresh()->planned_hours)->toBe('17.50');
});

it('keeps a module without hours when the catalogue has none, out of the sum', function () {
    $session = l2Session(modTraining(['7.00', null]));

    expect(array_map(fn (SessionModule $m) => $m->planned_minutes, modulesOf($session)))->toBe([420, null])
        ->and($session->fresh()->planned_hours)->toBe('7.00');
});

it('copies the modules of the new training when the training of a session changes', function () {
    $this->actingAs(admin(true));
    $session = l2Session(modTraining(['7.00']));
    $other = modTraining(['2.00', '3.00'], 'Plongeur');

    $this->put("/sessions/{$session->id}", [
        'training_id' => $other->id, 'kind' => 'inter', 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09',
        'location_kind' => 'remote', 'capacity' => '10', 'status' => 'planned', 'planned_hours' => '',
    ])->assertSessionHasNoErrors();

    expect(array_map(fn (SessionModule $m) => $m->planned_minutes, modulesOf($session)))->toBe([120, 180])
        ->and($session->fresh()->planned_hours)->toBe('5.00');
});

it('ignores typed hours on a session update: its hours are the sum of its modules', function () {
    $this->actingAs(admin(true));
    $session = l2Session(modTraining(['7.00', '14.00']));

    $this->put("/sessions/{$session->id}", [
        'training_id' => $session->training_id, 'kind' => 'inter', 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09',
        'location_kind' => 'remote', 'capacity' => '10', 'status' => 'planned', 'planned_hours' => '35',
    ])->assertSessionHasNoErrors();

    expect($session->fresh()->planned_hours)->toBe('21.00');
});

// ---------------------------------------------------------------- migration

it('gives every existing session one interval module by migration, at its dates and hours, once', function () {
    $session = l2Session(modTraining(['7.00', '14.00'], 'HACCP'));
    $empty = l2Session(modTraining([], 'Sans heures'));
    DB::table('session_modules')->delete();
    DB::table('training_sessions')->where('id', $session->id)->update(['planned_hours' => '10.33']);
    DB::table('training_sessions')->where('id', $empty->id)->update(['planned_hours' => null]);
    $trashed = l2Session(modTraining([], 'À la corbeille'));
    DB::table('session_modules')->where('training_session_id', $trashed->id)->delete();
    $trashed->delete();

    $migration = require database_path('migrations/2026_12_17_000002_backfill_session_modules.php');
    $migration->backfill();
    $migration->backfill();

    $modules = modulesOf($session);
    expect($modules)->toHaveCount(1)
        ->and($modules[0]->title)->toBe('HACCP')
        ->and($modules[0]->planned_minutes)->toBe(620)
        ->and($modules[0]->dating_mode)->toBe('interval')
        ->and($modules[0]->position)->toBe(1)
        ->and($modules[0]->modality_id)->toBe($session->modality_id)
        ->and($modules[0]->starts_on->toDateString())->toBe('2026-12-07')
        ->and($modules[0]->ends_on->toDateString())->toBe('2026-12-09')
        ->and($session->fresh()->planned_hours)->toBe('10.33')
        ->and(modulesOf($empty)[0]->planned_minutes)->toBeNull()
        ->and(modulesOf($trashed))->toHaveCount(1);
});

// ---------------------------------------------------------------- add, change, delete

it('adds, changes and deletes a module, the session hours following', function () {
    $this->actingAs(commercial());
    $session = l2Session(modTraining(['7.00', '14.00']));

    $this->post("/sessions/{$session->id}/modules", modPayload())->assertRedirect()->assertSessionHasNoErrors();
    $added = collect(modulesOf($session))->firstWhere('title', 'Hygiène');
    expect($added->planned_minutes)->toBe(210)->and($added->position)->toBe(3)
        ->and($added->starts_on->toDateString())->toBe('2026-12-07')
        ->and($session->fresh()->planned_hours)->toBe('24.50');

    $this->put("/sessions/{$session->id}/modules/{$added->id}", modPayload(['title' => 'Hygiène alimentaire', 'planned_hours' => '1,25', 'starts_on' => '2026-12-08', 'ends_on' => '2026-12-08']))
        ->assertSessionHasNoErrors();
    expect($added->fresh()->title)->toBe('Hygiène alimentaire')->and($added->fresh()->planned_minutes)->toBe(75)
        ->and($added->fresh()->starts_on->toDateString())->toBe('2026-12-08')
        ->and($session->fresh()->planned_hours)->toBe('22.25');

    $this->delete("/sessions/{$session->id}/modules/{$added->id}")->assertSessionHasNoErrors();
    expect($added->fresh()->trashed())->toBeTrue()->and($session->fresh()->planned_hours)->toBe('21.00');
});

it('validates a module in French', function () {
    $this->actingAs(commercial());
    $session = l2Session(modTraining(['7.00']));

    $this->post("/sessions/{$session->id}/modules", modPayload(['title' => '', 'planned_hours' => '0', 'dating_mode' => 'autre']))
        ->assertSessionHasErrors([
            'title' => 'Indiquez l\'intitulé du module.',
            'planned_hours' => 'Les heures prévues doivent être supérieures à 0.',
            'dating_mode' => 'Mode de datation inconnu.',
        ]);
    $this->post("/sessions/{$session->id}/modules", modPayload(['title' => str_repeat('a', 201), 'planned_hours' => 'beaucoup']))
        ->assertSessionHasErrors(['title' => 'L\'intitulé ne doit pas dépasser 200 caractères.', 'planned_hours' => 'Heures prévues invalides (ex. 7 ou 3,5).']);
    $this->post("/sessions/{$session->id}/modules", modPayload(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-08']))
        ->assertSessionHasErrors(['starts_on' => 'Les dates du module doivent rester dans celles de la session (du 07/12/2026 au 09/12/2026).']);
    $this->post("/sessions/{$session->id}/modules", modPayload(['starts_on' => '2026-12-09', 'ends_on' => '2026-12-08']))
        ->assertSessionHasErrors(['ends_on' => 'La date de fin doit être postérieure ou égale à la date de début.']);
    $this->post("/sessions/{$session->id}/modules", modPayload(['modality_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]))
        ->assertSessionHasErrors(['modality_id' => 'Modalité de formation inconnue.']);
    expect(modulesOf($session))->toHaveCount(1);
});

it('warns when trainees are planned above the new hours, leaving their enrolments unchanged', function () {
    $training = modTraining(['7.00', '14.00']);
    $session = l2Session($training);
    $enrolment = l2Enrol(l2Dossier(null, $training), $session, makePerson('Durand'));
    expect($enrolment->planned_hours)->toBe('21.00');
    $this->actingAs(admin(true));
    $big = modulesOf($session)[1];

    $this->put("/sessions/{$session->id}/modules/{$big->id}", modPayload(['title' => 'Module 2', 'planned_hours' => '7']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('warning', '1 stagiaire a des heures prévues supérieures à celles de la session (14,00 h) : vérifiez son inscription.');

    expect($enrolment->fresh()->planned_hours)->toBe('21.00')->and($session->fresh()->planned_hours)->toBe('14.00');
    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('planning.warnings.0', '1 stagiaire a des heures prévues supérieures à celles de la session (14,00 h) : vérifiez son inscription.'));
});

it('flags an interval module outside the dates of the session once they change', function () {
    $this->actingAs(admin(true));
    $session = l2Session(modTraining(['7.00']));

    $this->put("/sessions/{$session->id}", [
        'training_id' => $session->training_id, 'kind' => 'inter', 'starts_on' => '2026-12-08', 'ends_on' => '2026-12-10',
        'location_kind' => 'remote', 'capacity' => '10', 'status' => 'planned',
    ])->assertSessionHasNoErrors();

    $module = modulesOf($session)[0];
    expect($module->starts_on->toDateString())->toBe('2026-12-07');
    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->component('sessions/sessions/show')
        ->where('planning.modules.0.id', $module->id)
        ->where('planning.modules.0.planned', '7 h')
        ->where('planning.modules.0.planned_hours', '7')
        ->where('planning.modules.0.dating_mode', 'interval')
        ->where('planning.modules.0.out_of_range', true)
        ->where('planning.total', '7 h')
        ->where('planning.warnings.0', 'Le module « Module 1 » sort des dates de la session.'));
});

it('switches the dating mode of a module', function () {
    $this->actingAs(commercial());
    $session = l2Session(modTraining(['7.00']));
    $module = modulesOf($session)[0];

    $this->post("/sessions/{$session->id}/modules/{$module->id}/mode", ['dating_mode' => 'seances'])->assertSessionHasNoErrors();
    expect($module->fresh()->dating_mode)->toBe('seances');

    $this->post("/sessions/{$session->id}/modules/{$module->id}/mode", ['dating_mode' => 'jamais'])
        ->assertSessionHasErrors(['dating_mode' => 'Mode de datation inconnu.']);

    $this->post("/sessions/{$session->id}/modules/{$module->id}/mode", ['dating_mode' => 'interval'])->assertSessionHasNoErrors();
    expect($module->fresh()->dating_mode)->toBe('interval')->and($module->fresh()->hasActivity())->toBeFalse();
});

it('refuses to change the modules of a cancelled session', function () {
    $this->actingAs(commercial());
    $session = l2Session(modTraining(['7.00']));
    $module = modulesOf($session)[0];
    app(SessionWriter::class)->cancel($session, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);

    $this->post("/sessions/{$session->id}/modules", modPayload())->assertStatus(409);
    $this->delete("/sessions/{$session->id}/modules/{$module->id}")->assertStatus(409);
    expect($module->fresh()->trashed())->toBeFalse();
});

it('keeps a modality used by a session module', function () {
    $session = l2Session(modTraining([]));
    $modality = crmItem(ReferenceList::TrainingModality, 'Mixte');
    expect(ReferenceUsage::isInUse($modality))->toBeFalse();

    DB::table('session_modules')->where('training_session_id', $session->id)->update(['modality_id' => $modality->id]);

    expect(ReferenceUsage::isInUse($modality))->toBeTrue();
});

// ---------------------------------------------------------------- rights

it('asks sessions.sessions.update and its scope to change the modules', function () {
    $owner = commercial();
    $this->actingAs($owner);
    $session = l2Session(modTraining(['7.00']));
    $module = modulesOf($session)[0];

    $this->actingAs(l2User([Permissions::SESSIONS_VIEW => null]));
    $this->post("/sessions/{$session->id}/modules", modPayload())->assertForbidden();
    $this->delete("/sessions/{$session->id}/modules/{$module->id}")->assertForbidden();

    // Sees every session, changes only his own.
    $this->actingAs(l2User([Permissions::SESSIONS_VIEW => null, Permissions::SESSIONS_UPDATE => AccessScope::Own]));
    $this->post("/sessions/{$session->id}/modules", modPayload())->assertForbidden();
    $this->post("/sessions/{$session->id}/modules/{$module->id}/mode", ['dating_mode' => 'seances'])->assertForbidden();
    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page->where('planning.can_manage', false));

    // Outside the view scope: 404.
    $this->actingAs(l2User([Permissions::SESSIONS_VIEW => AccessScope::Own, Permissions::SESSIONS_UPDATE => AccessScope::Own]));
    $this->post("/sessions/{$session->id}/modules", modPayload())->assertNotFound();

    $this->actingAs($owner);
    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page->where('planning.can_manage', true));
    expect(modulesOf($session))->toHaveCount(1)->and($module->fresh()->dating_mode)->toBe('interval');
});

it('answers 404 for a module of another session, of another organisme or a malformed id', function () {
    $this->actingAs(admin(true));
    $session = l2Session(modTraining(['7.00']));
    $other = l2Session(modTraining(['3.00'], 'Autre'));
    $foreign = modulesOf($other)[0];

    $this->delete("/sessions/{$session->id}/modules/{$foreign->id}")->assertNotFound();
    $this->delete("/sessions/{$session->id}/modules/pas-un-uuid")->assertNotFound();

    $tenant = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($tenant);
    [$theirSession, $theirModule] = app(TenantContext::class)->run($tenant, function () {
        $s = l2Session(Training::query()->create(['title' => 'Chez eux', 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id, 'duration_hours' => '7']));

        return [$s, modulesOf($s)[0]];
    });
    inTenant();

    $this->put("/sessions/{$session->id}/modules/{$theirModule->id}", modPayload())->assertNotFound();
    $this->post("/sessions/{$theirSession->id}/modules", modPayload())->assertNotFound();
    expect(SessionModule::query()->whereKey($theirModule->id)->exists())->toBeFalse()
        ->and(app(SessionModules::class))->toBeInstanceOf(SessionModules::class);
});
