<?php

use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionStatus;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function sessTraining(string $title = 'HACCP 3 jours', array $attributes = []): Training
{
    return Training::query()->create([
        'title' => $title,
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'duration_hours' => '21.00',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sessPayload(Training $training, array $overrides = []): array
{
    return [
        'training_id' => $training->id,
        'kind' => 'inter',
        'organisation_id' => null,
        'starts_on' => '2026-12-07',
        'ends_on' => '2026-12-09',
        'planned_hours' => '',
        'location_kind' => 'address',
        'location_text' => '12 rue des Écoles, 69001 Lyon',
        'capacity' => 10,
        'responsible_user_id' => null,
        'status' => 'planned',
        'notes' => null,
        ...$overrides,
    ];
}

function cancelReason(string $label = 'Effectif insuffisant'): ReferenceItem
{
    return crmItem(ReferenceList::CancelReason, $label);
}

// ---------------------------------------------------------------- numbering

it('numbers the sessions per organisme and Paris year', function () {
    $training = sessTraining();
    $writer = app(SessionWriter::class);

    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00', 'Europe/Paris'));
    expect($writer->create(sessPayload($training))->number)->toBe('S-2026-0001')
        ->and($writer->create(sessPayload($training))->number)->toBe('S-2026-0002');

    // 23:30 UTC on 31 December is already 2027 in Paris.
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:30', 'UTC'));
    expect($writer->create(sessPayload($training))->number)->toBe('S-2027-0001');

    // Another organisme has its own sequence.
    $other = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($other);
    $number = app(TenantContext::class)->run($other, function () use ($writer) {
        $theirs = Training::query()->create(['title' => 'Chez eux', 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);

        return $writer->create(sessPayload($theirs))->number;
    });
    expect($number)->toBe('S-2027-0001');
    inTenant();
    expect($writer->create(sessPayload($training))->number)->toBe('S-2027-0002');
});

it('gives the number back when the creation fails inside its transaction', function () {
    $training = sessTraining();
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00', 'Europe/Paris'));
    // The number is taken, then the insert fails: the sequence must roll back with it.
    $fail = true;
    TrainingSession::creating(function () use (&$fail): void {
        if ($fail) {
            $fail = false;
            throw new RuntimeException('Échec au milieu de la création.');
        }
    });

    expect(fn () => app(SessionWriter::class)->create(sessPayload($training)))->toThrow(RuntimeException::class);
    expect(TrainingSession::query()->count())->toBe(0)
        ->and(app(SessionWriter::class)->create(sessPayload($training))->number)->toBe('S-2026-0001');
});

// ---------------------------------------------------------------- creation and validation

it('creates an inter session from the form, hours and DAS taken from the training', function () {
    $training = sessTraining();
    $user = commercial();
    $this->actingAs($user);

    $this->post('/sessions', sessPayload($training))->assertRedirect();

    $session = TrainingSession::query()->sole();
    expect($session->das_id)->toBe($training->das_id)
        ->and($session->planned_hours)->toBe('21.00')
        ->and($session->kind)->toBe('inter')
        ->and($session->capacity)->toBe(10)
        ->and($session->organisation_id)->toBeNull()
        ->and($session->starts_on->toDateString())->toBe('2026-12-07')
        ->and($session->responsible_user_id)->toBe($user->id)
        ->and($session->created_by)->toBe($user->id)
        ->and($session->status)->toBe(SessionStatus::Planned)
        ->and(AuditLog::query()->where('auditable_id', $session->id)->where('event', 'created')->exists())->toBeTrue();

    $this->get("/sessions/{$session->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sessions/sessions/show')
        ->where('session.number', $session->number)
        ->where('session.training.title', 'HACCP 3 jours')
        ->where('session.kind_label', 'Inter')
        ->where('session.status_label', 'Planifiée')
        ->where('session.planned_hours', '21,00')
        ->where('session.capacity', 10));
});

it('keeps the hours typed (French decimal) instead of the training ones', function () {
    $training = sessTraining();
    $this->actingAs(commercial());

    $session = app(SessionWriter::class)->create(sessPayload($training, ['planned_hours' => '17,5']));

    expect($session->planned_hours)->toBe('17.50');
});

it('creates an intra session for an entreprise, without places', function () {
    $training = sessTraining();
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $this->actingAs(commercial());

    $session = app(SessionWriter::class)->create(sessPayload($training, ['kind' => 'intra', 'organisation_id' => $organisation->id, 'capacity' => 12, 'location_kind' => 'client', 'location_text' => null]));

    expect($session->organisation_id)->toBe($organisation->id)
        ->and($session->capacity)->toBeNull()
        ->and($session->location_kind)->toBe('client');

    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('session.organisation.label', 'Hôtel du Parc')
        ->where('session.location_label', 'Chez le client'));
});

it('refuses an intra session without an entreprise, or with a trashed or unknown one', function () {
    $training = sessTraining();
    $trashed = Organisation::query()->create(['name' => 'Fermée']);
    $trashed->delete();
    $this->actingAs(commercial());

    $this->post('/sessions', sessPayload($training, ['kind' => 'intra', 'organisation_id' => null]))
        ->assertSessionHasErrors(['organisation_id' => 'Choisissez l\'entreprise de la session intra.']);
    $this->post('/sessions', sessPayload($training, ['kind' => 'intra', 'organisation_id' => $trashed->id]))
        ->assertSessionHasErrors(['organisation_id' => 'Entreprise introuvable.']);
    $this->post('/sessions', sessPayload($training, ['kind' => 'intra', 'organisation_id' => 'pas-un-uuid']))
        ->assertSessionHasErrors('organisation_id');

    expect(TrainingSession::query()->count())->toBe(0);
});

it('refuses an end date before the start date, in French', function () {
    $training = sessTraining();
    $this->actingAs(commercial());

    $this->post('/sessions', sessPayload($training, ['starts_on' => '2026-12-09', 'ends_on' => '2026-12-07']))
        ->assertSessionHasErrors(['ends_on' => 'La date de fin doit être postérieure ou égale à la date de début.']);

    // One day: allowed.
    $this->post('/sessions', sessPayload($training, ['starts_on' => '2026-12-09', 'ends_on' => '2026-12-09']))->assertSessionHasNoErrors();
    expect(TrainingSession::query()->count())->toBe(1);
});

it('requires places for an inter session and an address for an address location', function () {
    $training = sessTraining();
    $this->actingAs(commercial());

    $this->post('/sessions', sessPayload($training, ['capacity' => null]))
        ->assertSessionHasErrors(['capacity' => 'Indiquez le nombre de places de la session inter.']);
    $this->post('/sessions', sessPayload($training, ['capacity' => 0]))->assertSessionHasErrors('capacity');
    $this->post('/sessions', sessPayload($training, ['location_text' => '']))
        ->assertSessionHasErrors(['location_text' => 'Indiquez l\'adresse.']);
    $this->post('/sessions', sessPayload($training, ['kind' => 'autre']))->assertSessionHasErrors('kind');

    expect(TrainingSession::query()->count())->toBe(0);
});

it('refuses an unknown or inactive training, or one of another organisme', function () {
    $inactive = sessTraining('Ancienne', ['is_active' => false]);
    $other = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($other);
    $theirs = app(TenantContext::class)->run($other, fn () => Training::query()->create(['title' => 'Chez eux', 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]));
    inTenant();
    $this->actingAs(commercial());

    $this->post('/sessions', sessPayload($inactive))->assertSessionHasErrors(['training_id' => 'Cette formation est désactivée.']);
    $this->post('/sessions', sessPayload($theirs))->assertSessionHasErrors(['training_id' => 'Formation introuvable.']);

    expect(TrainingSession::query()->count())->toBe(0);
});

it('refuses the creation without sessions.sessions.update', function () {
    $training = sessTraining();
    $this->actingAs(direction(twoFactor: true));

    $this->get('/sessions')->assertOk();
    $this->get('/sessions/create')->assertForbidden();
    $this->post('/sessions', sessPayload($training))->assertForbidden();
});

// ---------------------------------------------------------------- update, cancel, trash

it('updates a session, the training change bringing its DAS', function () {
    $training = sessTraining();
    $other = sessTraining('Sauveteur secouriste', ['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id, 'duration_hours' => '14.00']);
    $this->actingAs(commercial());
    $session = app(SessionWriter::class)->create(sessPayload($training));

    $this->put("/sessions/{$session->id}", sessPayload($other, ['status' => 'confirmed', 'capacity' => 8]))->assertRedirect("/sessions/{$session->id}");

    $session->refresh();
    expect($session->training_id)->toBe($other->id)
        ->and($session->das_id)->toBe($other->das_id)
        ->and($session->planned_hours)->toBe('14.00')
        ->and($session->status)->toBe(SessionStatus::Confirmed)
        ->and($session->capacity)->toBe(8)
        ->and($session->number)->toBe('S-'.CarbonImmutable::now('Europe/Paris')->year.'-0001');
});

it('cancels a session with a reason of the list, then refuses to change it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Paris'));
    $training = sessTraining();
    $this->actingAs(commercial());
    $session = app(SessionWriter::class)->create(sessPayload($training));

    $this->post("/sessions/{$session->id}/cancel", [])->assertSessionHasErrors(['cancel_reason_id' => 'Choisissez le motif d\'annulation.']);
    $this->post("/sessions/{$session->id}/cancel", ['cancel_reason_id' => crmItem(ReferenceList::BpfCategory, 'Particuliers')->id])
        ->assertSessionHasErrors('cancel_reason_id');

    $this->post("/sessions/{$session->id}/cancel", ['cancel_reason_id' => cancelReason()->id])->assertRedirect();
    $session->refresh();
    expect($session->status)->toBe(SessionStatus::Cancelled)
        ->and($session->cancel_reason_id)->toBe(cancelReason()->id)
        ->and($session->cancelled_on->toDateString())->toBe('2026-11-20');

    $this->post("/sessions/{$session->id}/cancel", ['cancel_reason_id' => cancelReason()->id])->assertStatus(409);
    // Already cancelled comes before the reason: 409 even without one.
    $this->post("/sessions/{$session->id}/cancel", [])->assertStatus(409);
    $this->put("/sessions/{$session->id}", sessPayload($training))->assertStatus(409);

    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('session.status_label', 'Annulée')->where('session.cancel_reason', 'Effectif insuffisant')
        ->where('can.update', false)->where('can.cancel', false));

    // The reason is now in use.
    expect(cancelReason()->isInUse())->toBeTrue();
});

it('sends a session to the trash and restores it with sessions.sessions.delete only', function () {
    $training = sessTraining();
    $session = app(SessionWriter::class)->create(sessPayload($training));

    $this->actingAs(commercial())->delete("/sessions/{$session->id}")->assertForbidden();

    $this->actingAs(admin(twoFactor: true))->delete("/sessions/{$session->id}")->assertRedirect('/sessions');
    expect($session->fresh()->trashed())->toBeTrue();

    $this->get('/sessions')->assertInertia(fn (Assert $page) => $page->has('sessions.data', 0));
    $this->get('/sessions?trashed=1')->assertInertia(fn (Assert $page) => $page->has('sessions.data', 1));
    $this->actingAs(commercial())->get("/sessions/{$session->id}")->assertNotFound();

    $this->actingAs(admin(twoFactor: true))->post("/sessions/{$session->id}/restore")->assertRedirect("/sessions/{$session->id}");
    expect($session->fresh()->trashed())->toBeFalse();
});

// ---------------------------------------------------------------- effective status

it('computes « En cours » and « Terminée » from the Paris dates while planned or confirmed', function () {
    $training = sessTraining();
    $session = app(SessionWriter::class)->create(sessPayload($training, ['starts_on' => '2026-12-07', 'ends_on' => '2026-12-09', 'status' => 'confirmed']));

    expect($session->effectiveStatus(CarbonImmutable::parse('2026-12-06')))->toBe(SessionStatus::Confirmed)
        ->and($session->effectiveStatus(CarbonImmutable::parse('2026-12-07')))->toBe(SessionStatus::Running)
        ->and($session->effectiveStatus(CarbonImmutable::parse('2026-12-09')))->toBe(SessionStatus::Running)
        ->and($session->effectiveStatus(CarbonImmutable::parse('2026-12-10')))->toBe(SessionStatus::Done);

    $session->forceFill(['status' => 'cancelled', 'cancel_reason_id' => cancelReason()->id, 'cancelled_on' => '2026-12-01'])->save();
    expect($session->effectiveStatus(CarbonImmutable::parse('2026-12-08')))->toBe(SessionStatus::Cancelled);

    // 00:30 in Paris on 7 December is still 6 December in UTC: already running.
    $session->forceFill(['status' => 'planned', 'cancel_reason_id' => null, 'cancelled_on' => null])->save();
    $this->travelTo(CarbonImmutable::parse('2026-12-07 00:30', 'Europe/Paris'));
    $this->actingAs(admin(twoFactor: true))->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('session.status', 'running')->where('session.status_label', 'En cours'));
    $this->get('/sessions?status=running')->assertInertia(fn (Assert $page) => $page->has('sessions.data', 1));
    $this->get('/sessions?status=planned')->assertInertia(fn (Assert $page) => $page->has('sessions.data', 0));
});

// ---------------------------------------------------------------- calendar

it('shows the sessions of a month in the calendar, with French month names', function () {
    $training = sessTraining();
    $writer = app(SessionWriter::class);
    $before = $writer->create(sessPayload($training, ['starts_on' => '2026-11-28', 'ends_on' => '2026-12-02']));
    $inside = $writer->create(sessPayload($training, ['starts_on' => '2026-12-15', 'ends_on' => '2026-12-15']));
    $writer->create(sessPayload($training, ['starts_on' => '2027-01-04', 'ends_on' => '2027-01-05']));
    $this->actingAs(commercial());

    $this->get('/sessions/calendar?month=2026-12')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sessions/sessions/calendar')
        ->where('month', '2026-12')
        ->where('label', 'Décembre 2026')
        ->where('prev', '2026-11')
        ->where('next', '2027-01')
        ->has('sessions', 2)
        ->where('sessions.0.id', $before->id)
        ->where('sessions.1.id', $inside->id)
        ->where('sessions.1.training.title', 'HACCP 3 jours'));

    // A malformed month: the current Paris month.
    $this->travelTo(CarbonImmutable::parse('2026-02-10 10:00', 'Europe/Paris'));
    $this->get('/sessions/calendar?month=2026-13')->assertInertia(fn (Assert $page) => $page->where('month', '2026-02')->where('label', 'Février 2026'));
});

// ---------------------------------------------------------------- catalogue guard

it('keeps a training used by a session (trashed one included) out of deletion', function () {
    $training = sessTraining();
    $free = sessTraining('Libre');
    $session = app(SessionWriter::class)->create(sessPayload($training));
    $session->delete();

    expect(TrainingUsage::isUsed($training->id))->toBeTrue()
        ->and(TrainingUsage::isUsed($free->id))->toBeFalse();

    $this->actingAs(admin(twoFactor: true))->from('/catalogue')->delete("/catalogue/{$training->id}")
        ->assertSessionHasErrors(['training' => 'Cette formation est utilisée (opportunités, devis, sessions) : désactivez-la plutôt.']);
    expect($training->fresh()->trashed())->toBeFalse();
});

it('answers 404 for a session of another organisme or a malformed id', function () {
    $other = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($other);
    $theirs = app(TenantContext::class)->run($other, function () {
        $training = Training::query()->create(['title' => 'Chez eux', 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);

        return app(SessionWriter::class)->create(sessPayload($training));
    });
    inTenant();
    $this->actingAs(admin(twoFactor: true));

    $this->get("/sessions/{$theirs->id}")->assertNotFound();
    $this->get('/sessions/pas-un-uuid')->assertNotFound();
    $this->put("/sessions/{$theirs->id}", [])->assertNotFound();
});

it('searches the entreprises of an intra session, identity only, with the update right', function () {
    Organisation::query()->create(['name' => 'Hôtel du Parc', 'siret' => '12345678900011']);
    $trashed = Organisation::query()->create(['name' => 'Parc fermé']);
    $trashed->delete();

    $this->actingAs(direction(twoFactor: true))->getJson('/sessions/organisations?q=parc')->assertForbidden();

    $this->actingAs(commercial())->getJson('/sessions/organisations?q=parc')->assertOk()
        ->assertExactJson(['entreprises' => [['id' => Organisation::query()->where('name', 'Hôtel du Parc')->value('id'), 'label' => 'Hôtel du Parc', 'siret' => '12345678900011']]]);
    $this->getJson('/sessions/organisations?q=p')->assertExactJson(['entreprises' => []]);
});

it('keeps showing the entreprise of an intra session once trashed', function () {
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $this->actingAs(commercial());
    $session = app(SessionWriter::class)->create(sessPayload(sessTraining(), ['kind' => 'intra', 'organisation_id' => $organisation->id]));
    $organisation->delete();

    $this->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('session.organisation.label', 'Hôtel du Parc')->where('session.organisation.trashed', true));
});

it('links the entreprise of an intra session only when the user can open its fiche', function () {
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $session = app(SessionWriter::class)->create(sessPayload(sessTraining(), ['kind' => 'intra', 'organisation_id' => $organisation->id]));

    $this->actingAs(commercial())->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('session.organisation.label', 'Hôtel du Parc')->where('session.organisation.can_open', true));
    $this->actingAs(userWithPermissions([Permissions::SESSIONS_VIEW]))->get("/sessions/{$session->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('session.organisation.label', 'Hôtel du Parc')->where('session.organisation.can_open', false));
});

it('refuses to restore an intra session whose entreprise is no longer live', function () {
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $session = app(SessionWriter::class)->create(sessPayload(sessTraining(), ['kind' => 'intra', 'organisation_id' => $organisation->id]));
    $session->delete();
    $organisation->delete();
    $this->actingAs(admin(twoFactor: true));

    $this->postJson("/sessions/{$session->id}/restore")->assertStatus(409)->assertJsonPath('message', SessionWriter::ORGANISATION_GONE);
    expect($session->fresh()->trashed())->toBeTrue();

    $organisation->restore();
    $this->post("/sessions/{$session->id}/restore")->assertRedirect("/sessions/{$session->id}");
    expect($session->fresh()->trashed())->toBeFalse();
});
