<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Gdpr\FicheReferences;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\PlacementPeriod;
use App\Modules\Sessions\Planning\PlacementPeriodHost;
use App\Modules\Sessions\Planning\PlacementPeriods;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
});

it('creates, changes and deletes a placement period of the session', function () {
    $session = plSession();
    $manager = plManager();

    $this->actingAs($manager)->post("/sessions/{$session->id}/placements", ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => ' Stage en entreprise '])
        ->assertRedirect("/sessions/{$session->id}")->assertSessionHasNoErrors()->assertSessionHas('status', 'Période de stage ajoutée.');
    $period = PlacementPeriod::query()->sole();
    expect($period->label)->toBe('Stage en entreprise')->and($period->starts_on->toDateString())->toBe('2026-09-21');

    $this->actingAs($manager)->put("/sessions/{$session->id}/placements/{$period->id}", ['starts_on' => '2026-09-22', 'ends_on' => '2026-09-29', 'label' => 'Stage d\'application'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($period->refresh()->ends_on->toDateString())->toBe('2026-09-29')->and($period->label)->toBe('Stage d\'application');

    $this->actingAs($manager)->delete("/sessions/{$session->id}/placements/{$period->id}")->assertRedirect()->assertSessionHas('status', 'Période de stage supprimée.');
    expect(PlacementPeriod::query()->count())->toBe(0);
});

it('validates a placement period in French', function () {
    $session = plSession();
    $url = "/sessions/{$session->id}/placements";

    $this->actingAs(plManager())->post($url, ['starts_on' => '', 'ends_on' => '', 'label' => ''])->assertSessionHasErrors([
        'starts_on' => 'Indiquez la date de début.',
        'ends_on' => 'Indiquez la date de fin.',
        'label' => 'Indiquez l\'intitulé du stage.',
    ]);
    $this->actingAs(plManager())->post($url, ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-20', 'label' => 'Stage'])
        ->assertSessionHasErrors(['ends_on' => 'La date de fin doit être postérieure ou égale à la date de début.']);
    $this->actingAs(plManager())->post($url, ['starts_on' => '2026-09-21', 'ends_on' => '2026-10-20', 'label' => str_repeat('a', 121)])
        ->assertSessionHasErrors([
            'label' => 'L\'intitulé ne doit pas dépasser 120 caractères.',
            'ends_on' => 'Le stage doit rester dans les dates de la session (du 01/09/2026 au 30/09/2026).',
        ]);
    expect(PlacementPeriod::query()->count())->toBe(0);
});

it('gives a trainee a host entreprise and own dates, which win over the common ones', function () {
    $session = plSession();
    $dossier = l2Dossier();
    $durand = l2Enrol($dossier, $session, makePerson('Durand'));
    $petit = l2Enrol($dossier, $session, makePerson('Petit'));
    $host = Organisation::query()->create(['name' => 'Brasserie du Port']);
    $period = PlacementPeriods::save($session, ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage en entreprise']);
    $manager = plManager();

    $this->actingAs($manager)->put("/sessions/{$session->id}/placements/{$period->id}/hosts/{$durand->id}", [
        'organisation_id' => $host->id, 'starts_on' => '2026-09-28', 'ends_on' => '2026-09-30',
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', 'Stage du stagiaire enregistré.');
    $this->actingAs($manager)->put("/sessions/{$session->id}/placements/{$period->id}/hosts/{$petit->id}", [
        'organisation_id' => $host->id, 'starts_on' => '', 'ends_on' => '',
    ])->assertSessionHasNoErrors();

    expect(PlacementPeriods::datesFor($period, $durand->id))->toBe(['from' => '2026-09-28', 'to' => '2026-09-30'])
        ->and(PlacementPeriods::datesFor($period, $petit->id))->toBe(['from' => '2026-09-21', 'to' => '2026-09-25'])
        ->and(PlacementPeriodHost::query()->count())->toBe(2);

    // Own dates come in pairs.
    $this->actingAs($manager)->put("/sessions/{$session->id}/placements/{$period->id}/hosts/{$petit->id}", ['organisation_id' => '', 'starts_on' => '2026-09-28', 'ends_on' => ''])
        ->assertSessionHasErrors(['ends_on' => 'Indiquez la date de fin du stage du stagiaire.']);
    // An entreprise in the trash is not chosen.
    $gone = Organisation::query()->create(['name' => 'Fermée']);
    $gone->delete();
    $this->actingAs($manager)->put("/sessions/{$session->id}/placements/{$period->id}/hosts/{$petit->id}", ['organisation_id' => $gone->id])
        ->assertSessionHasErrors(['organisation_id' => 'Entreprise introuvable.']);

    $this->actingAs($manager)->delete("/sessions/{$session->id}/placements/{$period->id}/hosts/{$petit->id}")->assertRedirect();
    expect(PlacementPeriodHost::query()->count())->toBe(1);

    // An enrolment of another session: 404.
    $stranger = l2Enrol(l2Dossier(), plSession(), makePerson('Autre'));
    $this->actingAs($manager)->put("/sessions/{$session->id}/placements/{$period->id}/hosts/{$stranger->id}", ['organisation_id' => $host->id])->assertNotFound();
});

it('keeps showing a host entreprise put in the trash, « (supprimée) »', function () {
    $session = plSession();
    $durand = l2Enrol(l2Dossier(), $session, makePerson('Durand', ['first_name' => 'Léa']));
    $host = Organisation::query()->create(['name' => 'Brasserie du Port']);
    $period = PlacementPeriods::save($session, ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage en entreprise']);
    PlacementPeriods::setHost($period, $durand, ['organisation_id' => $host->id, 'starts_on' => null, 'ends_on' => null]);
    $host->delete();

    $this->actingAs(plManager())->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('planning.placements.0.label', 'Stage en entreprise')
        ->where('planning.placements.0.trainees.0.trainee', 'Léa Durand')
        ->where('planning.placements.0.trainees.0.host.label', 'Brasserie du Port (supprimée)')
        ->where('planning.placements.0.trainees.0.host.trashed', true)
        ->where('planning.placements.0.trainees.0.from', '2026-09-21')
        ->where('planning.placements.0.trainees.0.own_dates', false));
});

it('names the host entreprises to the CRM: kept by an import undo, repointed by a merge', function () {
    $session = plSession();
    $durand = l2Enrol(l2Dossier(), $session, makePerson('Durand'));
    $host = Organisation::query()->create(['name' => 'Brasserie du Port']);
    $kept = Organisation::query()->create(['name' => 'Brasserie du Port SARL']);
    $period = PlacementPeriods::save($session, ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage']);
    PlacementPeriods::setHost($period, $durand, ['organisation_id' => $host->id, 'starts_on' => null, 'ends_on' => null]);

    expect(FicheReferences::linked('organisation', $host->id))->toBeTrue();
    FicheReferences::merge('organisation', $kept->id, $host->id);
    expect(PlacementPeriodHost::query()->sole()->organisation_id)->toBe($kept->id);
});

it('needs sessions.seances.manage and its scope; keeps other organismes out (RLS)', function () {
    $session = plSession();
    $period = PlacementPeriods::save($session, ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage']);
    $input = ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage'];

    $this->actingAs(userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW]))->post("/sessions/{$session->id}/placements", $input)->assertForbidden();
    $das = l2User([Permissions::SESSIONS_VIEW => null, Permissions::SEANCES_VIEW => null, Permissions::SEANCES_MANAGE => AccessScope::Das]);
    $this->actingAs($das)->delete("/sessions/{$session->id}/placements/{$period->id}")->assertForbidden();

    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, function () use ($other) {
        plSetUp($other);

        return PlacementPeriods::save(plSession(), ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage']);
    });
    inTenant();
    expect(PlacementPeriod::query()->find($foreign->id))->toBeNull();
    $this->actingAs(plManager())->delete("/sessions/{$session->id}/placements/{$foreign->id}")->assertNotFound();
    // A period of another session through this session's URL: 404.
    $elsewhere = PlacementPeriods::save(plSession(), $input);
    $this->actingAs(plManager())->delete("/sessions/{$session->id}/placements/{$elsewhere->id}")->assertNotFound();
});

it('searches the host entreprises for who plans the session (identity only, never cached)', function () {
    $session = plSession();
    Organisation::query()->create(['name' => 'Brasserie du Port']);

    $this->actingAs(plManager())->getJson("/sessions/{$session->id}/placements/organisations?q=brass")->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('entreprises.0.label', 'Brasserie du Port');
    $this->actingAs(userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW]))
        ->getJson("/sessions/{$session->id}/placements/organisations?q=brass")->assertForbidden();
});
