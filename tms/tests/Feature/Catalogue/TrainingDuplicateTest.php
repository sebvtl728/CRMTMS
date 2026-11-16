<?php

use App\Modules\Catalogue\Brochures\Brochure;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Permissions;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingDuplicator;
use App\Modules\Catalogue\TrainingModule;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    tenant();
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function duplicableTraining(array $overrides = []): Training
{
    $training = Training::query()->create([
        'code' => 'HACCP-3J',
        'title' => 'HACCP 3 jours',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'duration_hours' => '21',
        'duration_days' => '3',
        'audience' => 'Cuisiniers',
        'prerequisites' => 'Aucun',
        'price_company' => '1250.50',
        'price_individual' => '990',
        'is_active' => true,
        'description' => 'Hygiène alimentaire.',
        'objectives' => ['A', 'B'],
        'methods' => 'Cas pratiques.',
        'evaluation' => 'QCM.',
        'sanction' => 'Attestation.',
        'accessibility' => 'PMR.',
        'access_terms' => '7 jours avant.',
        'results' => '4,6/5.',
        ...$overrides,
    ]);
    $training->syncFundingModes([crmItem(ReferenceList::FundingMode, 'CPF')->id, crmItem(ReferenceList::FundingMode, 'OPCO')->id]);
    $training->modules()->create(['position' => 0, 'title' => 'Module 1', 'duration_hours' => '7', 'modality_id' => crmItem(ReferenceList::TrainingModality, 'Présentiel')->id, 'content' => 'Contenu 1']);
    $training->modules()->create(['position' => 1, 'title' => 'Module 2', 'duration_hours' => '14', 'content' => 'Contenu 2']);

    return $training;
}

it('duplicates a training as an inactive independent copy and opens it for editing', function () {
    $this->actingAs($admin = admin(twoFactor: true));
    $original = duplicableTraining();
    TrainingUsage::register('test-sessions', fn ($id) => $id === $original->id);

    $response = $this->post("/catalogue/{$original->id}/duplicate");

    $copy = Training::query()->where('id', '!=', $original->id)->sole();
    $response->assertRedirect("/catalogue/{$copy->id}/edit")
        ->assertSessionHas('status', 'Formation dupliquée : vérifiez-la puis activez-la.');

    expect($copy->title)->toBe('Copie de HACCP 3 jours')
        ->and($copy->code)->toBeNull()
        ->and($copy->is_active)->toBeFalse()
        ->and($copy->das_id)->toBe($original->das_id)
        ->and($copy->duration_hours)->toBe('21.00')
        ->and($copy->duration_days)->toBe('3.00')
        ->and($copy->price_company)->toBe('1250.50')
        ->and($copy->price_individual)->toBe('990.00')
        ->and($copy->objectives)->toBe(['A', 'B'])
        ->and($copy->audience)->toBe('Cuisiniers')
        ->and($copy->prerequisites)->toBe('Aucun')
        ->and($copy->description)->toBe('Hygiène alimentaire.')
        ->and($copy->methods)->toBe('Cas pratiques.')
        ->and($copy->evaluation)->toBe('QCM.')
        ->and($copy->sanction)->toBe('Attestation.')
        ->and($copy->accessibility)->toBe('PMR.')
        ->and($copy->access_terms)->toBe('7 jours avant.')
        ->and($copy->results)->toBe('4,6/5.')
        ->and($copy->created_by)->toBe($admin->id)
        ->and($copy->fundingModes()->pluck('reference_items.label')->sort()->values()->all())->toBe(['CPF', 'OPCO']);

    $modules = $copy->modules()->get();
    expect($modules->pluck('title')->all())->toBe(['Module 1', 'Module 2'])
        ->and($modules->pluck('content')->all())->toBe(['Contenu 1', 'Contenu 2'])
        ->and($modules->pluck('duration_hours')->all())->toBe(['7.00', '14.00'])
        ->and($modules[0]->modality_id)->toBe($original->modules()->first()->modality_id)
        ->and($modules->pluck('id')->intersect($original->modules()->pluck('id'))->all())->toBe([]);

    // Independence: editing the copy leaves the original (used elsewhere) untouched.
    $this->put("/catalogue/{$copy->id}/edit", ['title' => 'Autre', 'das_id' => $copy->das_id, 'modules' => []])->assertRedirect('/catalogue');
    $original->refresh();
    expect($original->title)->toBe('HACCP 3 jours')
        ->and($original->is_active)->toBeTrue()
        ->and($original->modules()->count())->toBe(2)
        ->and($original->fundingModes()->count())->toBe(2)
        ->and(TrainingUsage::isUsed($copy->id))->toBeFalse();
});

it('does not link the brochures to the copy', function () {
    $this->actingAs(admin(twoFactor: true));
    $original = duplicableTraining();
    $brochure = Brochure::query()->create(['title' => 'Plaquette']);
    $brochure->trainings()->attach($original->id, ['tenant_id' => tenant()->id]);

    $this->post("/catalogue/{$original->id}/duplicate")->assertRedirect();

    $copy = Training::query()->where('id', '!=', $original->id)->sole();
    expect($copy->id)->not->toBe($original->id)
        ->and($brochure->trainings()->pluck('trainings.id')->all())->toBe([$original->id]);
});

it('truncates the copied title to the title maximum length', function () {
    $this->actingAs(admin(twoFactor: true));
    $original = duplicableTraining(['title' => str_repeat('é', 200)]);

    $this->post("/catalogue/{$original->id}/duplicate")->assertRedirect();

    $copy = Training::query()->where('id', '!=', $original->id)->sole();
    expect(mb_strlen($copy->title))->toBe(200)
        ->and($copy->title)->toStartWith('Copie de é');
});

it('journals the creation of the copy with its origin', function () {
    $this->actingAs(admin(twoFactor: true));
    $original = duplicableTraining();

    $this->post("/catalogue/{$original->id}/duplicate")->assertRedirect();

    $copy = Training::query()->where('id', '!=', $original->id)->sole();
    $log = AuditLog::query()->where('event', 'created')->where('auditable_type', 'training')->where('auditable_id', $copy->id)->sole();
    expect($log->new_values['duplicated_from'])->toBe($original->id)
        ->and($log->new_values['title'])->toBe('Copie de HACCP 3 jours');
    // Module content is masked in the journal like any module creation.
    expect(AuditLog::query()->where('auditable_type', 'training_module')->get()->pluck('new_values.content')->filter()->unique()->values()->all())->toBe(['[masqué]']);
});

it('is reserved to catalogue.manage and unknown trainings are 404', function () {
    $original = duplicableTraining();

    $this->actingAs(userWithPermissions([Permissions::VIEW]))->post("/catalogue/{$original->id}/duplicate")->assertForbidden();
    expect(Training::query()->count())->toBe(1);

    $this->actingAs(admin(twoFactor: true))->post('/catalogue/'.Str::uuid7().'/duplicate')->assertNotFound();
});

it('does not leave a half copy when a step fails', function () {
    $this->actingAs(admin(twoFactor: true));
    $original = duplicableTraining();
    TrainingModule::creating(fn () => throw new RuntimeException('boom'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->post("/catalogue/{$original->id}/duplicate"))->toThrow(RuntimeException::class);

    expect(Training::query()->count())->toBe(1);
});

it('shows the status message on the edit page', function () {
    $this->actingAs(admin(twoFactor: true));
    $original = duplicableTraining();

    $this->followingRedirects()->post("/catalogue/{$original->id}/duplicate")
        ->assertInertia(fn (Assert $p) => $p->component('catalogue/form')->where('status', 'Formation dupliquée : vérifiez-la puis activez-la.'));
});

it('journals the copy like any creation: no timestamps, authors nor deleted_at', function () {
    $this->actingAs(admin(twoFactor: true));
    $original = duplicableTraining();
    // A copy whose attributes carry deleted_at (as a model loaded with all its columns does).
    $original->forceFill(['deleted_at' => null])->saveQuietly();

    $this->post("/catalogue/{$original->fresh()->id}/duplicate")->assertRedirect();

    $copy = Training::query()->where('id', '!=', $original->id)->sole();
    $log = AuditLog::query()->where('event', 'created')->where('auditable_type', 'training')->where('auditable_id', $copy->id)->sole();
    expect(array_keys($log->new_values))->not->toContain('deleted_at')->not->toContain('created_at')
        ->not->toContain('updated_at')->not->toContain('created_by')->not->toContain('updated_by');
    expect(TrainingDuplicator::AUDIT_IGNORED)->toContain('deleted_at');
});
