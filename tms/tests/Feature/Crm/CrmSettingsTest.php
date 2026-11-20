<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Public\FollowUpSettings;
use App\Modules\Crm\Settings\CrmFollowUpSettings;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('defaults to 6 and 12 months, 24 months of retention and the second stage as lead threshold', function () {
    $contacted = ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->orderBy('position')->skip(1)->firstOrFail();

    expect(CrmFollowUpSettings::reactivationMonths())->toBe([6, 12])
        ->and(FollowUpSettings::reactivationMonths())->toBe([6, 12])
        ->and(CrmFollowUpSettings::retentionMonths())->toBe(24)
        ->and(CrmFollowUpSettings::leadStageId())->toBe($contacted->id)
        ->and($contacted->label)->toBe('Contacté');
});

it('saves the delays, refuses a delay outside 1..36 months or a stage of another list', function () {
    $study = crmItem(ReferenceList::PipelineStage, 'Étude');
    $this->actingAs(admin(twoFactor: true))->put('/admin/crm-settings', [
        'reactivation_months' => [12, 6, 6], 'lead_stage_id' => $study->id, 'retention_months' => 36,
    ])->assertRedirect('/admin/crm-settings')->assertSessionHasNoErrors();

    expect(CrmFollowUpSettings::reactivationMonths())->toBe([6, 12])
        ->and(CrmFollowUpSettings::leadStageId())->toBe($study->id)
        ->and(CrmFollowUpSettings::retentionMonths())->toBe(36)
        ->and(AuditLog::query()->where('event', 'crm.settings.updated')->exists())->toBeTrue();

    $this->put('/admin/crm-settings', [
        'reactivation_months' => [0, 40], 'lead_stage_id' => crmItem(ReferenceList::ActionType, 'Appel')->id, 'retention_months' => 0,
    ])->assertSessionHasErrors([
        'reactivation_months.0' => 'Un délai de réactivation est un nombre de mois de 1 à 36.',
        'lead_stage_id' => 'Choisissez une étape du pipeline.',
        'retention_months' => 'La fenêtre de rétention est un nombre de mois de 1 à 120.',
    ]);

    // No delay at all: reactivation is disabled.
    $this->put('/admin/crm-settings', ['reactivation_months' => [], 'lead_stage_id' => $study->id, 'retention_months' => 24])->assertSessionHasNoErrors();
    expect(CrmFollowUpSettings::reactivationMonths())->toBe([]);
});

it('lets only settings.manage change them', function () {
    $this->actingAs(admin(twoFactor: true))->get('/admin/crm-settings')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('admin/crm-settings')->where('settings.reactivation_months', [6, 12])->has('stages'));
    $this->actingAs(commercial())->get('/admin/crm-settings')->assertForbidden();
    $this->actingAs(commercial())->put('/admin/crm-settings', ['reactivation_months' => [6]])->assertForbidden();
});

it('grants the Formateur role crm.activities.view and crm.activities.update on its own records once', function () {
    PermissionSync::run();
    $trainer = userWithRole(Role::Formateur);

    expect($trainer->can(Permissions::ACTIVITIES_VIEW))->toBeTrue()
        ->and($trainer->can(Permissions::ACTIVITIES_UPDATE))->toBeTrue()
        ->and($trainer->can(Permissions::FICHES_VIEW))->toBeFalse()
        ->and(Scopes::of($trainer, Permissions::ACTIVITIES_VIEW))->toBe(AccessScope::Own)
        ->and(Scopes::of($trainer, Permissions::ACTIVITIES_UPDATE))->toBe(AccessScope::Own);
});

it('marks « Réussi » as reached in the CAP FORMATION references', function () {
    expect(crmItem(ReferenceList::ActionResult, 'Réussi')->meta['reached'] ?? null)->toBeTrue()
        ->and(crmItem(ReferenceList::ActionResult, 'Échec')->meta['reached'] ?? null)->toBeNull();
});
