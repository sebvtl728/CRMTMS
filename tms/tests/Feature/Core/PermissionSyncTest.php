<?php

use App\Modules\Core\Access\PermissionModel;
use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Permissions as CrmPermissions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array<string, list<string>> role name => sorted permission names, in $tenant
 */
function matrixOf(Tenant $tenant): array
{
    return app(TenantContext::class)->run($tenant, function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return RoleModel::query()->with('permissions')->get()
            ->mapWithKeys(fn (RoleModel $r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()])
            ->all();
    });
}

/**
 * Simulates a tenant created before the CRM module existed: crm.* are neither
 * granted nor recorded as offered.
 */
function forgetCrmOffers(Tenant $tenant): void
{
    app(TenantContext::class)->run($tenant, function () {
        foreach (RoleModel::all() as $role) {
            $role->revokePermissionTo(array_values(array_intersect(CrmPermissions::all(), $role->permissions->pluck('name')->all())));
        }
        DB::table('role_permission_defaults_applied')->where('permission', 'like', 'crm.%')->delete();
    });
}

it('registers the CRM permissions in the catalogue and their default matrix', function () {
    expect(CrmPermissions::all())->toBe([
        'crm.fiches.view', 'crm.fiches.update', 'crm.fiches.delete', 'crm.merge',
        'crm.opportunities.view', 'crm.opportunities.update', 'crm.opportunities.delete',
        'crm.quotes.view', 'crm.quotes.update', 'crm.quotes.delete',
        'crm.activities.view', 'crm.activities.update', 'crm.activities.delete', 'crm.activities.team',
        'crm.revenue.view', 'crm.revenue.manage',
        'crm.import', 'crm.export', 'crm.gdpr',
    ])
        ->and(Permissions::all())->toContain(...CrmPermissions::all())
        ->and(Permissions::defaultsFor(Role::AdminOrganisme))->toContain(...CrmPermissions::all())
        ->and(CrmPermissions::defaultsFor(Role::Direction))->toBe(['crm.fiches.view', 'crm.opportunities.view', 'crm.quotes.view', 'crm.activities.view', 'crm.activities.team', 'crm.revenue.view', 'crm.revenue.manage', 'crm.export', 'crm.gdpr'])
        ->and(CrmPermissions::defaultsFor(Role::Commercial))->toBe([...CrmPermissions::domains(), 'crm.merge', 'crm.revenue.view', 'crm.import', 'crm.export'])
        ->and(CrmPermissions::defaultsFor(Role::ChargeRelations))->toBe(CrmPermissions::defaultsFor(Role::Commercial))
        ->and(CrmPermissions::defaultsFor(Role::Assistant))->toBe(['crm.fiches.view', 'crm.opportunities.view', 'crm.quotes.view', 'crm.activities.view', 'crm.fiches.update', 'crm.opportunities.update', 'crm.quotes.update', 'crm.activities.update'])
        ->and(CrmPermissions::defaultsFor(Role::ResponsableDas))->toBe(['crm.fiches.view', 'crm.opportunities.view', 'crm.quotes.view', 'crm.activities.view', 'crm.revenue.view', 'crm.export']);
});

it('gives a new tenant the full matrix, CRM included', function () {
    $tenant = Tenant::factory()->create();
    $matrix = matrixOf($tenant);

    expect($matrix['admin_organisme'])->toBe(collect(Permissions::all())->sort()->values()->all())
        ->and($matrix['direction'])->toBe(['audit.view', 'catalogue.delete', 'catalogue.manage', 'catalogue.view', 'crm.activities.team', 'crm.activities.view', 'crm.export', 'crm.fiches.view', 'crm.gdpr', 'crm.opportunities.view', 'crm.quotes.view', 'crm.revenue.manage', 'crm.revenue.view', 'exports.run'])
        ->and($matrix['assistant'])->toBe(['catalogue.view', 'crm.activities.update', 'crm.activities.view', 'crm.fiches.update', 'crm.fiches.view', 'crm.opportunities.update', 'crm.opportunities.view', 'crm.quotes.update', 'crm.quotes.view'])
        ->and($matrix['responsable_das'])->toBe(['catalogue.view', 'crm.activities.view', 'crm.export', 'crm.fiches.view', 'crm.opportunities.view', 'crm.quotes.view', 'crm.revenue.view']);
});

it('sync adds new default permissions to existing tenants once', function () {
    $tenant = Tenant::factory()->create();
    forgetCrmOffers($tenant);
    PermissionModel::query()->where('name', 'like', 'crm.%')->delete();

    expect(matrixOf($tenant)['commercial'])->toBe(['catalogue.view']);

    $this->artisan('tms:permissions:sync')->assertSuccessful();

    expect(PermissionModel::query()->where('name', 'like', 'crm.%')->count())->toBe(19)
        ->and(matrixOf($tenant)['commercial'])->toBe(['catalogue.view', 'crm.activities.delete', 'crm.activities.update', 'crm.activities.view', 'crm.export', 'crm.fiches.delete', 'crm.fiches.update', 'crm.fiches.view', 'crm.import', 'crm.merge', 'crm.opportunities.delete', 'crm.opportunities.update', 'crm.opportunities.view', 'crm.quotes.delete', 'crm.quotes.update', 'crm.quotes.view', 'crm.revenue.view'])
        ->and(matrixOf($tenant)['direction'])->toBe(['audit.view', 'catalogue.delete', 'catalogue.manage', 'catalogue.view', 'crm.activities.team', 'crm.activities.view', 'crm.export', 'crm.fiches.view', 'crm.gdpr', 'crm.opportunities.view', 'crm.quotes.view', 'crm.revenue.manage', 'crm.revenue.view', 'exports.run']);

    // Idempotent.
    $before = matrixOf($tenant);
    $rows = app(TenantContext::class)->run($tenant, fn () => DB::table('role_permission_defaults_applied')->count());
    $this->artisan('tms:permissions:sync')->assertSuccessful();

    expect(matrixOf($tenant))->toBe($before)
        ->and(app(TenantContext::class)->run($tenant, fn () => DB::table('role_permission_defaults_applied')->count()))->toBe($rows);
});

it('sync offers the catalogue permissions once to existing tenants', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->run($tenant, function () {
        foreach (RoleModel::all() as $role) {
            $role->revokePermissionTo(array_values(array_intersect(['catalogue.view', 'catalogue.manage'], $role->permissions->pluck('name')->all())));
        }
        DB::table('role_permission_defaults_applied')->where('permission', 'like', 'catalogue.%')->delete();
        RoleModel::findByName('assistant')->revokePermissionTo('crm.fiches.update');
    });

    PermissionSync::run();

    expect(matrixOf($tenant)['commercial'])->toContain('catalogue.view')->not->toContain('catalogue.manage')
        ->and(matrixOf($tenant)['direction'])->toContain('catalogue.view', 'catalogue.manage')
        ->and(matrixOf($tenant)['admin_organisme'])->toContain('catalogue.view', 'catalogue.manage')
        // Offered once: what an administrator removed earlier stays removed.
        ->and(matrixOf($tenant)['assistant'])->toBe(['catalogue.view', 'crm.activities.update', 'crm.activities.view', 'crm.fiches.view', 'crm.opportunities.update', 'crm.opportunities.view', 'crm.quotes.update', 'crm.quotes.view']);

    app(TenantContext::class)->run($tenant, fn () => RoleModel::findByName('commercial')->revokePermissionTo('catalogue.view'));
    PermissionSync::run();
    expect(matrixOf($tenant)['commercial'])->not->toContain('catalogue.view');
});

it('sync never re-adds a permission an admin removed', function () {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->run($tenant, function () {
        RoleModel::findByName('commercial')->revokePermissionTo('crm.fiches.delete');
        RoleModel::findByName('direction')->revokePermissionTo('exports.run');
    });

    PermissionSync::run();

    expect(matrixOf($tenant)['commercial'])->not->toContain('crm.fiches.delete')
        ->and(matrixOf($tenant)['direction'])->not->toContain('exports.run');
});

it('sync reaches suspended tenants and leaves no context behind', function () {
    $tenant = Tenant::factory()->suspended()->create();
    forgetCrmOffers($tenant);

    PermissionSync::run();

    expect(matrixOf($tenant)['assistant'])->toBe(['catalogue.view', 'crm.activities.update', 'crm.activities.view', 'crm.fiches.update', 'crm.fiches.view', 'crm.opportunities.update', 'crm.opportunities.view', 'crm.quotes.update', 'crm.quotes.view'])
        ->and(app(TenantContext::class)->current())->toBeNull();
});

it('keeps the offers of each tenant under row level security', function () {
    $a = Tenant::factory()->create();
    Tenant::factory()->create();

    $count = app(TenantContext::class)->run($a, fn () => DB::table('role_permission_defaults_applied')->distinct()->count('tenant_id'));

    expect($count)->toBe(1)
        ->and(DB::table('role_permission_defaults_applied')->count())->toBe(0);
});

it('shares the CRM permissions of the user with the frontend', function () {
    $this->actingAs(commercial())->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => collect($p)->contains('crm.fiches.view') && ! collect($p)->contains('crm.gdpr')));
});

it('reports the failing tenant and exits with an error', function () {
    $bad = Tenant::factory()->create(['slug' => 'broken-org']);
    Tenant::factory()->create(['slug' => 'fine-org']);

    $fine = Tenant::query()->where('slug', 'fine-org')->firstOrFail();
    forgetCrmOffers($bad);
    forgetCrmOffers($fine);

    DB::listen(function ($query) {
        if (str_contains($query->sql, 'role_permission_defaults_applied') && str_starts_with(strtolower($query->sql), 'insert')
            && app(TenantContext::class)->current()?->slug === 'broken-org') {
            throw new RuntimeException('boom');
        }
    });

    $this->artisan('tms:permissions:sync')
        ->expectsOutputToContain('Échec pour l\'organisme broken-org')
        ->assertExitCode(1);

    // The other organisme is still brought up to date.
    expect(matrixOf($fine)['assistant'])->toBe(['catalogue.view', 'crm.activities.update', 'crm.activities.view', 'crm.fiches.update', 'crm.fiches.view', 'crm.opportunities.update', 'crm.opportunities.view', 'crm.quotes.update', 'crm.quotes.view']);
});

it('exposes the tenants that failed in ForEachTenant', function () {
    $a = Tenant::factory()->create(['slug' => 'aaa-org']);
    Tenant::factory()->create(['slug' => 'bbb-org']);

    $failed = ForEachTenant::run(function (Tenant $tenant) use ($a) {
        if ($tenant->is($a)) {
            throw new RuntimeException('boom');
        }
    });

    expect(array_map(fn (Tenant $t) => $t->slug, $failed))->toContain('aaa-org')->not->toContain('bbb-org');
});
