<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\LegalForm;
use App\Modules\Core\Platform\TenantProvisioner;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

const SYSTEM_MESSAGE = 'Valeur système : désactivation impossible';

afterEach(fn () => ReferenceUsage::flush());

function seedCap(): void
{
    CapFormationReferenceSeeder::run(tenant());
}

function stage(string $code): ReferenceItem
{
    return ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->where('code', $code)->firstOrFail();
}

/** Registers the fake usage (reference_usage_probes.origin_id) and uses an item. */
function useOrigin(ReferenceItem $item): void
{
    ReferenceUsage::register(ReferenceList::ContactOrigin, 'reference_usage_probes', 'origin_id');
    DB::table('reference_usage_probes')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => tenant()->id,
        'origin_id' => $item->id,
    ]);
}

it('seeds CAP FORMATION pipeline with probabilities', function () {
    seedCap();

    expect(ReferenceItem::options(ReferenceList::PipelineStage)->map(fn ($i) => [$i->label, $i->meta['probability']])->all())
        ->toBe([['Nouveau', 10], ['Contacté', 20], ['Étude', 40], ['Proposition', 60], ['Négociation', 80], ['Gagné', 100], ['Perdu', 0]]);

    $won = stage('gagne');
    $lost = stage('perdu');
    expect($won->is_system)->toBeTrue()->and($won->meta['outcome'])->toBe('won')
        ->and($lost->is_system)->toBeTrue()->and($lost->meta['outcome'])->toBe('lost')
        ->and(stage('nouveau')->is_system)->toBeFalse();
});

it('seeds the exact CAP FORMATION values of every list', function () {
    seedCap();
    $labels = fn (ReferenceList $l) => ReferenceItem::options($l)->pluck('label')->all();

    expect($labels(ReferenceList::Das))->toBe(['Métiers supports', 'Compétences transversales', 'Hôtellerie-restauration', 'Mobilités professionnelles'])
        ->and($labels(ReferenceList::ActionType))->toBe(['Prise de rendez-vous', 'Rappel', 'Envoi de nouveauté', 'Suivi', 'Rendez-vous physique', 'Envoi de devis', 'Appel', 'E-mail', 'Note'])
        ->and($labels(ReferenceList::ActionResult))->toBe(['Réussi', 'Échec', 'En attente', 'À replanifier'])
        ->and($labels(ReferenceList::ContactOrigin))->toBe(['Salon', 'Recommandation', 'Site web', 'Appel à froid', 'Réseau', 'Réseaux sociaux', 'Appel entrant'])
        ->and($labels(ReferenceList::Priority))->toBe(['Urgent', 'Élevé', 'Moyen', 'Bas'])
        ->and($labels(ReferenceList::EstablishmentType))->toBe(['Restaurant traditionnel', 'Restauration rapide', 'Restauration collective', 'Hôtel-restaurant', 'Hôtel', 'Autre'])
        ->and($labels(ReferenceList::CompanySize))->toBe(['0 salarié', '1-9', '10-49', '50-249', '250 et plus'])
        ->and($labels(ReferenceList::FundingMode))->toBe(['CPF', 'OPCO', 'France Travail', 'Entreprise', 'Personnel', 'Région', 'Autre'])
        ->and($labels(ReferenceList::ProfessionalSituation))->toBe(['Salarié', "Demandeur d'emploi", 'Indépendant', 'En reconversion', 'Étudiant', 'Autre'])
        ->and($labels(ReferenceList::DecisionRole))->toBe(['Décideur', 'Prescripteur', 'Utilisateur', 'Financeur', 'Autre'])
        ->and($labels(ReferenceList::LossReason))->toBe(['Prix', 'Financement refusé', 'Concurrent', 'Pas de besoin', 'Calendrier', 'Sans réponse', 'Autre'])
        ->and($labels(ReferenceList::Sector))->toBe(['Hôtellerie-restauration', 'Commerce', 'Industrie', 'Services', 'Santé-social', 'Collectivité', 'Autre'])
        ->and($labels(ReferenceList::LegalFormOrg))->toBe(array_map(fn (LegalForm $f) => $f->label(), LegalForm::cases()));
});

it('derives stable slug codes from labels', function () {
    seedCap();

    expect(ReferenceItem::query()->where('list', ReferenceList::Das)->pluck('code')->all())
        ->toBe(['metiers-supports', 'competences-transversales', 'hotellerie-restauration', 'mobilites-professionnelles'])
        ->and(ReferenceItem::query()->where('list', ReferenceList::ProfessionalSituation)->pluck('code')->all())
        ->toContain('demandeur-demploi');
});

it('is idempotent and never overwrites an edited item', function () {
    seedCap();
    $count = ReferenceItem::query()->count();
    stage('nouveau')->update(['label' => 'Entrant', 'meta' => ['probability' => 15, 'outcome' => null]]);
    stage('perdu')->delete(); // not a system rule violation at model level: only the HTTP layer guards it

    seedCap();

    expect(ReferenceItem::query()->count())->toBe($count - 1)
        ->and(stage('nouveau')->label)->toBe('Entrant')
        ->and(stage('nouveau')->meta['probability'])->toBe(15);
});

it('has an arch-clean RLS table and isolates tenants', function () {
    seedCap();
    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, fn () => CapFormationReferenceSeeder::run($other));

    $own = ReferenceItem::query()->count();
    expect($own)->toBeGreaterThan(50)
        ->and(app(TenantContext::class)->run($other, fn () => ReferenceItem::query()->count()))->toBe($own);
    expect(DB::table('reference_items')->count())->toBe($own); // RLS hides the other tenant
});

it('keeps inactive items out of options but in the list', function () {
    seedCap();
    $item = ReferenceItem::query()->where('list', ReferenceList::ContactOrigin)->where('code', 'salon')->firstOrFail();

    $this->actingAs(admin(twoFactor: true))
        ->putJson("/admin/references/contact_origin/{$item->id}", ['label' => 'Salon', 'is_active' => false])
        ->assertRedirect();

    expect(ReferenceItem::options(ReferenceList::ContactOrigin)->pluck('code')->all())->not->toContain('salon')
        ->and($item->fresh())->not->toBeNull()
        ->and($item->fresh()->label)->toBe('Salon')
        ->and($item->fresh()->is_active)->toBeFalse();

    $this->get('/admin/references')->assertInertia(fn (Assert $page) => $page
        ->where('lists.contact_origin.items', fn ($items) => collect($items)->contains(fn ($i) => $i['code'] === 'salon' && $i['is_active'] === false)));
});

it('refuses to delete or deactivate a system item', function () {
    seedCap();
    $won = stage('gagne');

    $this->actingAs(admin(twoFactor: true))
        ->deleteJson("/admin/references/pipeline_stage/{$won->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', SYSTEM_MESSAGE);

    $this->putJson("/admin/references/pipeline_stage/{$won->id}", ['label' => 'Gagné', 'is_active' => false, 'meta' => ['probability' => 100]])
        ->assertStatus(422)
        ->assertJsonPath('message', SYSTEM_MESSAGE);

    expect(stage('gagne')->is_active)->toBeTrue();
});

it('keeps the code and the won/lost probability of system items on update', function () {
    seedCap();
    $won = stage('gagne');

    $this->actingAs(admin(twoFactor: true))
        ->putJson("/admin/references/pipeline_stage/{$won->id}", ['label' => 'Signé', 'code' => 'x', 'is_active' => true, 'meta' => ['probability' => 50, 'color' => '#00aa00']])
        ->assertRedirect();

    $won = stage('gagne');
    expect($won->label)->toBe('Signé')->and($won->code)->toBe('gagne')
        ->and($won->meta['probability'])->toBe(100)->and($won->meta['outcome'])->toBe('won')
        ->and($won->meta['color'])->toBe('#00aa00');
});

it('lets a used item only be deactivated', function () {
    seedCap();
    $salon = ReferenceItem::query()->where('list', ReferenceList::ContactOrigin)->where('code', 'salon')->firstOrFail();
    useOrigin($salon);
    expect($salon->isInUse())->toBeTrue();

    $this->actingAs(admin(twoFactor: true))
        ->deleteJson("/admin/references/contact_origin/{$salon->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'utilisée') && str_contains($m, 'Désactivez'));
    expect($salon->fresh())->not->toBeNull();

    $this->putJson("/admin/references/contact_origin/{$salon->id}", ['label' => 'Salon', 'is_active' => false])->assertRedirect();
    expect($salon->fresh()->is_active)->toBeFalse();
});

it('reports unused items as not in use and ignores unregistered lists', function () {
    seedCap();
    $salon = ReferenceItem::query()->where('list', ReferenceList::ContactOrigin)->where('code', 'salon')->firstOrFail();
    expect($salon->isInUse())->toBeFalse(); // empty registry

    useOrigin($salon);
    $web = ReferenceItem::query()->where('list', ReferenceList::ContactOrigin)->where('code', 'site-web')->firstOrFail();
    $das = ReferenceItem::query()->where('list', ReferenceList::Das)->firstOrFail();
    expect($web->isInUse())->toBeFalse()->and($das->isInUse())->toBeFalse();
});

it('soft deletes an unused custom item', function () {
    seedCap();
    $note = ReferenceItem::query()->where('list', ReferenceList::ActionType)->where('code', 'note')->firstOrFail();

    $this->actingAs(admin(twoFactor: true))->delete("/admin/references/action_type/{$note->id}")->assertRedirect();

    expect(ReferenceItem::query()->find($note->id))->toBeNull()
        ->and(ReferenceItem::withTrashed()->find($note->id))->not->toBeNull();
});

it('creates items with a unique slug code, appended position and stage probability', function () {
    seedCap();
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->postJson('/admin/references/contact_origin', ['label' => 'Bouche à oreille'])->assertRedirect();
    $this->postJson('/admin/references/contact_origin', ['label' => 'Bouche à oreille'])->assertStatus(422)->assertJsonValidationErrors('label');
    $this->postJson('/admin/references/pipeline_stage', ['label' => 'Relance'])->assertStatus(422)->assertJsonValidationErrors('meta.probability');
    $this->postJson('/admin/references/pipeline_stage', ['label' => 'Relance', 'meta' => ['probability' => 101]])->assertStatus(422);
    $this->postJson('/admin/references/pipeline_stage', ['label' => 'Relance', 'meta' => ['probability' => 30, 'color' => 'red']])->assertStatus(422);
    $this->postJson('/admin/references/pipeline_stage', ['label' => 'Relance', 'meta' => ['probability' => 30, 'color' => '#ff8800']])->assertRedirect();

    $new = ReferenceItem::query()->where('list', ReferenceList::ContactOrigin)->where('label', 'Bouche à oreille')->sole();
    expect($new->code)->toBe('bouche-a-oreille')->and($new->position)->toBe(8)->and($new->is_system)->toBeFalse()->and($new->is_active)->toBeTrue();

    // A code freed by a deletion is never reused (history keeps pointing to it).
    $new->delete();
    $this->postJson('/admin/references/contact_origin', ['label' => 'Bouche à oreille'])->assertRedirect();
    expect(ReferenceItem::query()->where('list', ReferenceList::ContactOrigin)->where('label', 'Bouche à oreille')->sole()->code)->toBe('bouche-a-oreille-2');
});

it('does not let a non-system code be edited', function () {
    seedCap();
    $item = ReferenceItem::query()->where('list', ReferenceList::Das)->where('code', 'metiers-supports')->firstOrFail();

    $this->actingAs(admin(twoFactor: true))
        ->putJson("/admin/references/das/{$item->id}", ['label' => 'Métiers de support', 'code' => 'autre'])
        ->assertRedirect();

    expect($item->fresh()->code)->toBe('metiers-supports')->and($item->fresh()->label)->toBe('Métiers de support');
});

it('reorders items and persists positions', function () {
    seedCap();
    $ids = ReferenceItem::options(ReferenceList::Priority)->pluck('id')->all();
    $reversed = array_reverse($ids);

    $this->actingAs(admin(twoFactor: true))
        ->postJson('/admin/references/priority/reorder', ['ids' => $reversed])
        ->assertRedirect();

    expect(ReferenceItem::options(ReferenceList::Priority)->pluck('id')->all())->toBe($reversed);

    $this->postJson('/admin/references/priority/reorder', ['ids' => array_slice($ids, 1)])->assertStatus(422);
    $foreign = ReferenceItem::options(ReferenceList::Das)->first()->id;
    $this->postJson('/admin/references/priority/reorder', ['ids' => [...$ids, $foreign]])->assertStatus(422);
});

it('does not reach an item through another list', function () {
    seedCap();
    $das = ReferenceItem::query()->where('list', ReferenceList::Das)->firstOrFail();

    $this->actingAs(admin(twoFactor: true))
        ->putJson("/admin/references/priority/{$das->id}", ['label' => 'X'])
        ->assertNotFound();
});

it('only references.manage may edit', function () {
    seedCap();
    $item = stage('nouveau');

    $this->actingAs(commercial(twoFactor: true))->get('/admin/references')->assertForbidden();
    $this->postJson('/admin/references/priority', ['label' => 'X'])->assertForbidden();
    $this->putJson("/admin/references/pipeline_stage/{$item->id}", ['label' => 'X'])->assertForbidden();
    $this->deleteJson("/admin/references/pipeline_stage/{$item->id}")->assertForbidden();
    $this->postJson('/admin/references/priority/reorder', ['ids' => []])->assertForbidden();
});

it('renders the administration page', function () {
    seedCap();

    $this->actingAs(admin(twoFactor: true))->get('/admin/references')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/references/index')
            ->has('lists', 23)
            ->where('lists.pipeline_stage.label', 'Étapes du pipeline')
            ->where('lists.pipeline_stage.items.0.meta.probability', 10)
            ->where('lists.pipeline_stage.items.5.is_system', true));
});

it('audits reference changes', function () {
    seedCap();
    $item = ReferenceItem::query()->where('list', ReferenceList::Das)->firstOrFail();

    $this->actingAs(admin(twoFactor: true))->putJson("/admin/references/das/{$item->id}", ['label' => 'Nouveau libellé'])->assertRedirect();

    expect(AuditLog::query()->where('auditable_id', $item->id)->where('event', 'updated')->exists())->toBeTrue();
});

it('seeds CAP FORMATION through the provisioner callback', function () {
    [$tenant] = app(TenantProvisioner::class)->create('Autre OF', 'autre-of', [], fn (Tenant $t) => CapFormationReferenceSeeder::run($t));

    expect(app(TenantContext::class)->run($tenant, fn () => ReferenceItem::query()->count()))->toBeGreaterThan(50);
});

it('tms:tenant:create --seed-cap seeds the defaults, and not without the flag', function () {
    $this->artisan('tms:tenant:create', ['name' => 'Avec', '--slug' => 'avec', '--seed-cap' => true])->assertSuccessful();
    $this->artisan('tms:tenant:create', ['name' => 'Sans', '--slug' => 'sans'])->assertSuccessful();

    $count = fn (string $slug) => app(TenantContext::class)->run(Tenant::where('slug', $slug)->firstOrFail(), fn () => ReferenceItem::query()->count());
    expect($count('avec'))->toBeGreaterThan(50)->and($count('sans'))->toBe(0);
});

it('tms:install --seed-cap seeds the defaults', function () {
    Tenant::query()->delete();
    app(TenantContext::class)->clear();

    $this->artisan('tms:install', [
        '--tenant-name' => 'CAP FORMATION', '--admin-email' => 'admin@cap.test', '--admin-name' => 'Admin', '--seed-cap' => true,
    ])->assertSuccessful();

    $tenant = Tenant::sole();
    $labels = app(TenantContext::class)->run($tenant, fn () => ReferenceItem::options(ReferenceList::PipelineStage)->pluck('label')->all());
    expect($labels)->toBe(['Nouveau', 'Contacté', 'Étude', 'Proposition', 'Négociation', 'Gagné', 'Perdu']);
});

it('the dev DatabaseSeeder provisions a demo tenant with CAP FORMATION references, idempotently', function () {
    Tenant::query()->delete();
    app(TenantContext::class)->clear();

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $tenant = Tenant::sole();
    expect($tenant->slug)->toBe(config('tms.default_tenant'))
        ->and(app(TenantContext::class)->run($tenant, fn () => ReferenceItem::query()->count()))->toBe(139);
});

it('flags the CAP FORMATION meeting and call types for the planning', function () {
    seedCap();
    $planning = ReferenceItem::query()->where('list', ReferenceList::ActionType)->orderBy('position')->get()
        ->mapWithKeys(fn (ReferenceItem $i) => [$i->code => $i->meta['planning'] ?? null])->filter()->all();

    expect($planning)->toBe(['prise-de-rendez-vous' => 'meeting', 'rendez-vous-physique' => 'meeting', 'appel' => 'call']);
});

it('lets the admin set or clear the planning kind of an action type, only on that list', function () {
    seedCap();
    $this->actingAs(admin(twoFactor: true));

    $this->postJson('/admin/references/action_type', ['label' => 'Visite client', 'meta' => ['planning' => 'meeting']])->assertRedirect();
    $visit = ReferenceItem::query()->where('list', ReferenceList::ActionType)->where('code', 'visite-client')->sole();
    expect($visit->meta)->toBe(['planning' => 'meeting']);

    $this->putJson("/admin/references/action_type/{$visit->id}", ['label' => 'Visite client', 'meta' => ['planning' => 'call', 'color' => '#ff8800']])->assertRedirect();
    expect($visit->fresh()->meta)->toBe(['color' => '#ff8800', 'planning' => 'call']);
    $this->putJson("/admin/references/action_type/{$visit->id}", ['label' => 'Visite client', 'meta' => ['planning' => null]])->assertRedirect();
    expect($visit->fresh()->meta)->toBe(['color' => '#ff8800']);

    // A label-only update (e.g. deactivation from the list) keeps the flag.
    $call = ReferenceItem::query()->where('list', ReferenceList::ActionType)->where('code', 'appel')->sole();
    $this->putJson("/admin/references/action_type/{$call->id}", ['label' => 'Appel', 'is_active' => true])->assertRedirect();
    expect($call->fresh()->meta)->toBe(['planning' => 'call']);

    $this->postJson('/admin/references/action_type', ['label' => 'Autre', 'meta' => ['planning' => 'party']])->assertStatus(422)->assertJsonValidationErrors('meta.planning');
    $this->postJson('/admin/references/contact_origin', ['label' => 'Salon pro', 'meta' => ['planning' => 'meeting']])->assertRedirect();
    expect(ReferenceItem::query()->where('code', 'salon-pro')->sole()->meta)->toBeNull();

    $this->get('/admin/references')->assertInertia(fn (Assert $p) => $p->where('lists.action_type.has_planning', true)->where('lists.das.has_planning', false));
});

it('flags the meeting and call types of existing organismes by migration', function () {
    seedCap();
    ReferenceItem::query()->where('list', ReferenceList::ActionType)->get()->each(fn (ReferenceItem $i) => $i->update(['meta' => null]));
    $edited = ReferenceItem::query()->where('list', ReferenceList::ActionType)->where('code', 'appel')->sole();
    $edited->update(['meta' => ['color' => '#ff8800']]);
    $unflagged = ReferenceItem::query()->where('list', ReferenceList::ActionType)->where('code', 'rendez-vous-physique')->sole();
    $unflagged->update(['meta' => ['planning' => null]]); // the organisme removed the flag: kept removed

    (require database_path('migrations/2026_11_26_000001_flag_planning_action_types.php'))->up();

    $planning = ReferenceItem::query()->where('list', ReferenceList::ActionType)->orderBy('position')->get()
        ->mapWithKeys(fn (ReferenceItem $i) => [$i->code => $i->meta])->filter()->all();
    expect($planning)->toBe([
        'prise-de-rendez-vous' => ['planning' => 'meeting'],
        'rendez-vous-physique' => ['planning' => null],
        'appel' => ['color' => '#ff8800', 'planning' => 'call'],
    ]);
});

it('flags the CAP FORMATION « Proposition » stage as the stage of a sent quote', function () {
    seedCap();
    $flagged = ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->get()
        ->filter(fn (ReferenceItem $i) => ($i->meta['quote_stage'] ?? false) === true)->pluck('code')->all();

    expect(array_values($flagged))->toBe(['proposition']);
});

it('lets the admin choose one quote stage, never a closing one', function () {
    seedCap();
    $this->actingAs(admin(twoFactor: true));
    $stage = fn (string $code) => ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->where('code', $code)->sole();

    $this->putJson("/admin/references/pipeline_stage/{$stage('negociation')->id}", ['label' => 'Négociation', 'meta' => ['probability' => 80, 'quote_stage' => true]])->assertRedirect();
    expect($stage('negociation')->meta['quote_stage'])->toBeTrue()
        // Only one: the previous one is cleared.
        ->and($stage('proposition')->meta)->not->toHaveKey('quote_stage')
        ->and($stage('proposition')->meta['probability'])->toBe(60);

    $this->putJson("/admin/references/pipeline_stage/{$stage('gagne')->id}", ['label' => 'Gagné', 'meta' => ['quote_stage' => true]])
        ->assertStatus(422)->assertJsonValidationErrors(['meta.quote_stage' => 'Une étape gagnée ou perdue ne peut pas être l\'étape d\'un devis envoyé.']);

    // A label-only update keeps the flag; false removes it.
    $this->putJson("/admin/references/pipeline_stage/{$stage('negociation')->id}", ['label' => 'Négociation', 'is_active' => true, 'meta' => ['probability' => 80]])->assertRedirect();
    expect($stage('negociation')->meta['quote_stage'])->toBeTrue();
    $this->putJson("/admin/references/pipeline_stage/{$stage('negociation')->id}", ['label' => 'Négociation', 'meta' => ['probability' => 80, 'quote_stage' => false]])->assertRedirect();
    expect($stage('negociation')->meta)->not->toHaveKey('quote_stage');

    // Other lists ignore it.
    $this->postJson('/admin/references/contact_origin', ['label' => 'Salon pro', 'meta' => ['quote_stage' => true]])->assertRedirect();
    expect(ReferenceItem::query()->where('code', 'salon-pro')->sole()->meta)->toBeNull();

    $this->get('/admin/references')->assertInertia(fn (Assert $p) => $p->where('lists.pipeline_stage.has_quote_stage', true)->where('lists.das.has_quote_stage', false));
});

it('flags the « Proposition » stage of existing organismes by migration, unless one is chosen', function () {
    seedCap();
    $proposition = ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->where('code', 'proposition')->sole();
    $proposition->update(['meta' => ['probability' => 60, 'outcome' => null]]);

    (require database_path('migrations/2026_12_04_000002_flag_quote_pipeline_stage.php'))->up();
    expect($proposition->fresh()->meta['quote_stage'])->toBeTrue();

    // The organisme chose another stage (or none, false): kept.
    $proposition = $proposition->fresh();
    $proposition->update(['meta' => ['probability' => 60, 'outcome' => null]]);
    $other = ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->where('code', 'etude')->sole();
    $other->update(['meta' => ['probability' => 40, 'outcome' => null, 'quote_stage' => false]]);
    (require database_path('migrations/2026_12_04_000002_flag_quote_pipeline_stage.php'))->up();
    expect($proposition->fresh()->meta)->not->toHaveKey('quote_stage');
});
