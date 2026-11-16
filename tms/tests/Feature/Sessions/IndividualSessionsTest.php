<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Catalogue\Training;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Platform\TenantProvisioner;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceController;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\EnrolmentOptions;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Sessions\SessionKind;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(fn () => ReferenceUsage::flush());

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
});

function indTraining(): Training
{
    return Training::query()->where('title', 'HACCP 3 jours')->first()
        ?? Training::query()->create(['title' => 'HACCP 3 jours', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '21.00']);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function indPayload(array $overrides = []): array
{
    return [
        'training_id' => indTraining()->id,
        'kind' => 'individual',
        'organisation_id' => null,
        'modality_id' => crmItem(ReferenceList::TrainingModality, 'Présentiel')->id,
        'starts_on' => '2026-12-07',
        'ends_on' => '2026-12-09',
        'planned_hours' => '',
        'location_kind' => 'remote',
        'location_text' => null,
        'capacity' => null,
        'responsible_user_id' => null,
        'status' => 'planned',
        'notes' => null,
        ...$overrides,
    ];
}

function indDossier(?Organisation $organisation = null, $person = null)
{
    $training = indTraining();

    return app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => $person === null ? ($organisation ?? Organisation::query()->create(['name' => 'Hôtel du Parc']))->id : null,
        'account_person_id' => $person?->id,
        'das_id' => $training->das_id,
        'lines' => [['kind' => 'training', 'training_id' => $training->id, 'label' => $training->title, 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '1000', 'discount_percent' => '', 'pricing_mode' => 'per_trainee']],
    ]);
}

it('labels the three kinds and creates an individual session without places', function () {
    expect(SessionKind::Individual->label())->toBe('Individuel')
        ->and(array_column(SessionKind::options(), 'label'))->toBe(['Intra', 'Inter', 'Individuel']);

    $session = app(SessionWriter::class)->create(indPayload());

    expect($session->kind)->toBe('individual')->and($session->capacity)->toBeNull()->and($session->organisation_id)->toBeNull();
});

it('ignores places typed on an individual session and keeps its optional entreprise', function () {
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Parc']);

    $session = app(SessionWriter::class)->create(indPayload(['capacity' => 5, 'organisation_id' => $organisation->id]));

    expect($session->capacity)->toBeNull()->and($session->organisation_id)->toBe($organisation->id);
});

it('keeps the database rules of each kind', function () {
    $session = app(SessionWriter::class)->create(indPayload());

    expect(fn () => DB::table('training_sessions')->where('id', $session->id)->update(['capacity' => 3]))->toThrow(Exception::class);
});

it('takes one trainee only on an individual session, from any contractant', function () {
    $session = app(SessionWriter::class)->create(indPayload());
    $dossier = indDossier();
    $line = $dossier->lines()->firstOrFail();
    $enrolments = app(Enrolments::class);

    $first = $enrolments->enrol($dossier, $line, $session, makePerson('Premier')->id);
    expect($first->status)->toBe(EnrolmentStatus::Enrolled);

    try {
        $enrolments->enrol($dossier, $line, $session, makePerson('Second')->id);
        $this->fail('A second trainee was taken.');
    } catch (ValidationException $e) {
        expect($e->errors()['training_session_id'])->toBe(["Une session individuelle n'accueille qu'un stagiaire."]);
    }

    // Never a waiting list; a cancelled enrolment frees the place.
    expect(DB::table('enrolments')->where('training_session_id', $session->id)->count())->toBe(1);
    $enrolments->cancel($first);
    expect($enrolments->enrol($dossier, $line, $session, makePerson('Troisième')->id)->status)->toBe(EnrolmentStatus::Enrolled);
});

it('takes a particulier as contractant of an individual session', function () {
    $session = app(SessionWriter::class)->create(indPayload());
    $person = makePerson('Durand', ['is_individual' => true]);
    $dossier = indDossier(null, $person);

    $enrolment = app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, $person->id);

    expect($enrolment->status)->toBe(EnrolmentStatus::Enrolled);
});

it('refuses to change the kind of a session with trainees', function () {
    $session = app(SessionWriter::class)->create(indPayload());
    $dossier = indDossier();
    app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, makePerson('Stagiaire')->id);

    expect(fn () => app(SessionWriter::class)->update($session, indPayload(['kind' => 'inter', 'capacity' => 4])))
        ->toThrow(UserFacingHttpException::class);
});

it('requires a modality and takes Présentiel by default when none is sent on creation', function () {
    $writer = app(SessionWriter::class);

    try {
        $writer->create(indPayload(['modality_id' => '']));
        $this->fail('A session without modality was created.');
    } catch (ValidationException $e) {
        expect($e->errors()['modality_id'])->toBe(['Choisissez la modalité de formation.']);
    }

    $payload = indPayload();
    unset($payload['modality_id']);
    expect($writer->create($payload)->modality_id)->toBe(crmItem(ReferenceList::TrainingModality, 'Présentiel')->id);

    // Another organisme's value, or an unknown one, is refused.
    expect(fn () => $writer->create(indPayload(['modality_id' => (string) Str::uuid()])))->toThrow(ValidationException::class);
});

it('seeds the four modalities, in order', function () {
    expect(ReferenceItem::options(ReferenceList::TrainingModality)->pluck('label')->all())
        ->toBe(['Présentiel', 'Distanciel (classe virtuelle)', 'FOAD / e-learning', 'Mixte']);
});

it('creates the modalities of every existing organisme by migration, without duplicating', function () {
    $migration = require database_path('migrations/2026_12_12_000001_add_modality_and_individual_kind_to_training_sessions.php');
    [$other] = app(TenantProvisioner::class)->create('Deuxième', 'deuxieme', []);
    DB::table('reference_items')->where('list', 'training_modality')->delete();

    $migration->seedModalities();
    $migration->seedModalities();

    $labels = fn (Tenant $t) => app(TenantContext::class)->run($t, fn () => ReferenceItem::options(ReferenceList::TrainingModality)->pluck('label')->all());
    $expected = ['Présentiel', 'Distanciel (classe virtuelle)', 'FOAD / e-learning', 'Mixte'];
    expect($labels(tenant()))->toBe($expected)->and($labels($other))->toBe($expected)
        ->and(DB::table('reference_items')->where('list', 'training_modality')->whereNull('tenant_id')->count())->toBe(0);
});

it('keeps a used modality: not deletable, deactivated instead', function () {
    $session = app(SessionWriter::class)->create(indPayload());
    $modality = ReferenceItem::query()->findOrFail($session->modality_id);

    $this->actingAs(admin(twoFactor: true))
        ->delete("/admin/references/training_modality/{$modality->id}")
        ->assertSessionHasErrors(['item' => ReferenceController::IN_USE_MESSAGE]);
    expect($modality->fresh()->trashed())->toBeFalse();
});

it('shows, filters and edits the modality of sessions', function () {
    $presentiel = crmItem(ReferenceList::TrainingModality, 'Présentiel');
    $elearning = crmItem(ReferenceList::TrainingModality, 'FOAD / e-learning');
    $writer = app(SessionWriter::class);
    $writer->create(indPayload());
    $remote = $writer->create(indPayload(['modality_id' => $elearning->id]));
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->get("/sessions?modality={$elearning->id}")->assertInertia(fn (Assert $page) => $page
        ->has('sessions.data', 1)->where('sessions.data.0.id', $remote->id)
        ->where('sessions.data.0.modality', 'FOAD / e-learning')->where('filters.modality', $elearning->id)
        ->has('options.modality', 4));
    $this->get('/sessions/calendar?month=2026-12&modality='.$presentiel->id)->assertInertia(fn (Assert $page) => $page
        ->has('sessions', 1)->where('sessions.0.modality', 'Présentiel'));
    $this->get("/sessions/{$remote->id}")->assertInertia(fn (Assert $page) => $page->where('session.modality', 'FOAD / e-learning'));
    $this->get("/sessions/{$remote->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('session.modality_id', $elearning->id)->has('options.modality', 4)->where('options.default_modality', $presentiel->id));

    // Updating without the key keeps the stored modality.
    $payload = indPayload();
    unset($payload['modality_id']);
    expect($writer->update($remote, $payload)->modality_id)->toBe($elearning->id);
});

it('lists individual sessions among those a dossier can enrol on, free ones only', function () {
    $free = app(SessionWriter::class)->create(indPayload());
    $taken = app(SessionWriter::class)->create(indPayload());
    $dossier = indDossier();
    app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $taken, makePerson('Stagiaire')->id);

    $options = EnrolmentOptions::sessionsFor($dossier->load('lines'), admin(twoFactor: true));

    expect(array_column($options[$dossier->lines()->firstOrFail()->id], 'id'))->toBe([$free->id]);
});

it('migrates nothing for a new organisme: the seeder gives its modalities', function () {
    [$new] = app(TenantProvisioner::class)->create('Neuf', 'neuf', [], fn (Tenant $t) => CapFormationReferenceSeeder::run($t));

    expect(app(TenantContext::class)->run($new, fn () => ReferenceItem::query()->where('list', ReferenceList::TrainingModality)->count()))->toBe(4);
});
