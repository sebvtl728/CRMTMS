<?php

use App\Modules\Core\Platform\TenantProvisioner;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceController;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(fn () => ReferenceUsage::flush());

const LOT2A_LISTS = [ReferenceList::FundingType, ReferenceList::AbandonReason, ReferenceList::CancelReason, ReferenceList::BpfCategory, ReferenceList::EducationLevel];

function lot2aMigration(string $name): object
{
    return require database_path('migrations/'.$name.'.php');
}

/** @return array<string, list<array{string, string, int, array<string, mixed>|null}>> list => [code, label, position, meta] */
function lot2aItems(): array
{
    $out = [];
    foreach (LOT2A_LISTS as $list) {
        $out[$list->value] = ReferenceItem::query()->where('list', $list)->orderBy('position')
            ->get()->map(fn (ReferenceItem $i) => [$i->code, $i->label, $i->position, $i->meta])->all();
    }

    return $out;
}

it('seeds the lot 2a lists for a new organisme with the agreed values', function () {
    inTenant();
    CapFormationReferenceSeeder::run(tenant());
    $labels = fn (ReferenceList $l) => ReferenceItem::options($l)->pluck('label')->all();

    expect($labels(ReferenceList::FundingType))->toBe(['OPCO', 'Entreprise', 'CPF', 'France Travail', 'Région', 'Personnel'])
        ->and($labels(ReferenceList::AbandonReason))->toBe(['Démission', 'Maladie', 'Accident', 'Licenciement', 'Convenance personnelle', 'Autre'])
        ->and($labels(ReferenceList::BpfCategory))->toBe(["Salariés d'employeurs privés", "Demandeurs d'emploi", 'Particuliers', 'Autres'])
        ->and($labels(ReferenceList::EducationLevel))->toHaveCount(7)->and($labels(ReferenceList::EducationLevel)[0])->toBe('Sans diplôme')
        ->and($labels(ReferenceList::CancelReason))->not->toBeEmpty();

    $forceMajeure = ReferenceItem::options(ReferenceList::AbandonReason)->filter(fn ($i) => ($i->meta['force_majeure'] ?? false) === true)->pluck('code')->all();
    expect($forceMajeure)->toBe(['maladie', 'accident'])
        ->and(ReferenceItem::query()->where('list', ReferenceList::AbandonReason)->where('code', 'demission')->value('meta'))->toBeNull();

    $defaults = ReferenceItem::options(ReferenceList::FundingType)->mapWithKeys(fn ($i) => [$i->code => $i->meta['default_for'] ?? null])->filter()->all();
    expect($defaults)->toBe(['entreprise' => 'organisation', 'personnel' => 'person']);

    // No second « employment status » list: the professional situation is reused.
    expect(array_map(fn (ReferenceList $l) => $l->value, ReferenceList::cases()))->not->toContain('employment_status');
});

it('gives an organisme created later the same values (seeder through the provisioner)', function () {
    [$tenant] = app(TenantProvisioner::class)->create('Autre OF', 'autre-of', [], fn (Tenant $t) => CapFormationReferenceSeeder::run($t));

    app(TenantContext::class)->run($tenant, fn () => expect(ReferenceItem::query()->where('list', ReferenceList::FundingType)->count())->toBe(6));
});

it('creates the same values for an existing organisme by migration, without duplicating or resurrecting', function () {
    $existing = inTenant();
    $migration = lot2aMigration('2026_12_07_000002_seed_sessions_reference_lists');

    $migration->up();
    lot2aMigration('2026_12_13_000001_flag_default_funding_types')->up();
    $migrated = lot2aItems();
    expect($migrated['funding_type'])->toHaveCount(6)->and($migrated['abandon_reason'][1][3])->toBe(['force_majeure' => true]);

    // Same codes, labels, order and flags as a freshly seeded organisme.
    [$other] = app(TenantProvisioner::class)->create('Neuf', 'neuf', [], fn (Tenant $t) => CapFormationReferenceSeeder::run($t));
    $seeded = app(TenantContext::class)->run($other, fn () => lot2aItems());
    expect($migrated)->toBe($seeded);

    // Idempotent; a value the organisme deleted stays deleted; its edits are kept.
    ReferenceItem::query()->where('list', ReferenceList::FundingType)->where('code', 'cpf')->firstOrFail()->delete();
    ReferenceItem::query()->where('list', ReferenceList::FundingType)->where('code', 'opco')->firstOrFail()->update(['label' => 'OPCO (renommé)']);
    $migration->up();
    expect(ReferenceItem::query()->where('list', ReferenceList::FundingType)->count())->toBe(5)
        ->and(ReferenceItem::query()->where('list', ReferenceList::FundingType)->where('code', 'opco')->value('label'))->toBe('OPCO (renommé)');
    expect($existing->id)->toBe(tenant()->id);
});

it('migrates every organisme, each with its own data', function () {
    inTenant();
    [$other] = app(TenantProvisioner::class)->create('Deuxième', 'deuxieme', []);

    lot2aMigration('2026_12_07_000002_seed_sessions_reference_lists')->up();

    $count = fn (Tenant $t) => app(TenantContext::class)->run($t, fn () => ReferenceItem::query()->where('list', ReferenceList::BpfCategory)->count());
    expect($count(tenant()))->toBe(4)->and($count($other))->toBe(4)
        ->and(DB::table('reference_items')->where('list', 'bpf_category')->whereNull('tenant_id')->count())->toBe(0);
});

it('refuses to delete a value that is used and deactivates it instead', function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $level = ReferenceItem::query()->where('list', ReferenceList::EducationLevel)->where('code', 'niveau-4-baccalaureat')->firstOrFail();
    makePerson('Stagiaire', ['education_level_id' => $level->id]);

    $this->actingAs(admin(twoFactor: true))
        ->delete("/admin/references/education_level/{$level->id}")
        ->assertSessionHasErrors(['item' => ReferenceController::IN_USE_MESSAGE]);
    expect($level->fresh()->trashed())->toBeFalse();

    $this->put("/admin/references/education_level/{$level->id}", ['label' => $level->label, 'is_active' => false])->assertRedirect();
    expect($level->fresh()->is_active)->toBeFalse();
});

it('lets an administrator flag an abandon reason as force majeure', function () {
    inTenant();
    CapFormationReferenceSeeder::run(tenant());
    $admin = admin(twoFactor: true);
    $reason = ReferenceItem::query()->where('list', ReferenceList::AbandonReason)->where('code', 'licenciement')->firstOrFail();

    $this->actingAs($admin)->get('/admin/references')->assertInertia(fn (Assert $page) => $page
        ->where('lists.abandon_reason.has_force_majeure', true)->where('lists.funding_type.has_force_majeure', false)
        ->where('lists.abandon_reason.label', "Motifs d'abandon"));

    $this->put("/admin/references/abandon_reason/{$reason->id}", ['label' => 'Licenciement', 'meta' => ['force_majeure' => true]])->assertRedirect();
    expect($reason->fresh()->meta)->toBe(['force_majeure' => true]);

    $this->put("/admin/references/abandon_reason/{$reason->id}", ['label' => 'Licenciement', 'meta' => ['force_majeure' => false]])->assertRedirect();
    expect($reason->fresh()->meta)->toBeNull();

    // The flag exists on abandon reasons only: ignored elsewhere.
    $funding = ReferenceItem::query()->where('list', ReferenceList::FundingType)->where('code', 'cpf')->firstOrFail();
    $this->put("/admin/references/funding_type/{$funding->id}", ['label' => 'CPF', 'meta' => ['force_majeure' => true]])->assertRedirect();
    expect($funding->fresh()->meta)->toBeNull();
});

it('flags once, by migration, the funding types funding a new dossier by default', function () {
    inTenant();
    lot2aMigration('2026_12_07_000002_seed_sessions_reference_lists')->up();
    $type = fn (string $code) => ReferenceItem::query()->where('list', ReferenceList::FundingType)->where('code', $code)->firstOrFail();
    // The organisme already chose its default for particuliers: kept.
    $type('cpf')->update(['meta' => ['default_for' => 'person']]);
    $migration = lot2aMigration('2026_12_13_000001_flag_default_funding_types');

    $migration->up();
    expect($type('entreprise')->meta)->toBe(['default_for' => 'organisation'])
        ->and($type('personnel')->meta)->toBeNull()
        ->and($type('cpf')->meta)->toBe(['default_for' => 'person']);

    // Idempotent.
    $migration->up();
    expect(ReferenceItem::query()->where('list', ReferenceList::FundingType)->where('meta->default_for', 'organisation')->count())->toBe(1)
        ->and(ReferenceItem::query()->where('list', ReferenceList::FundingType)->where('meta->default_for', 'person')->pluck('code')->all())->toBe(['cpf']);
});

it('lets an administrator choose the funding type of each contractant, one per kind', function () {
    inTenant();
    CapFormationReferenceSeeder::run(tenant());
    $type = fn (string $code) => ReferenceItem::query()->where('list', ReferenceList::FundingType)->where('code', $code)->firstOrFail();
    $this->actingAs(admin(twoFactor: true))->get('/admin/references')->assertInertia(fn (Assert $page) => $page
        ->where('lists.funding_type.has_default_for', true)->where('lists.abandon_reason.has_default_for', false));

    $this->put("/admin/references/funding_type/{$type('cpf')->id}", ['label' => 'CPF', 'meta' => ['default_for' => 'person']])->assertRedirect();
    expect($type('cpf')->meta)->toBe(['default_for' => 'person'])
        ->and($type('personnel')->meta)->toBeNull()
        ->and($type('entreprise')->meta)->toBe(['default_for' => 'organisation']);

    $this->put("/admin/references/funding_type/{$type('cpf')->id}", ['label' => 'CPF', 'meta' => ['default_for' => null]])->assertRedirect();
    expect($type('cpf')->meta)->toBeNull();

    $this->put("/admin/references/funding_type/{$type('cpf')->id}", ['label' => 'CPF', 'meta' => ['default_for' => 'robot']])
        ->assertSessionHasErrors('meta.default_for');
    // Elsewhere the key is ignored.
    $reason = ReferenceItem::query()->where('list', ReferenceList::AbandonReason)->where('code', 'demission')->firstOrFail();
    $this->put("/admin/references/abandon_reason/{$reason->id}", ['label' => 'Démission', 'meta' => ['default_for' => 'person']])->assertRedirect();
    expect($reason->fresh()->meta)->toBeNull();
});
