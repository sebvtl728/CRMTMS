<?php

use App\Modules\Core\Access\PermissionModel;
use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
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
        'crm.view', 'crm.create', 'crm.update', 'crm.delete', 'crm.merge', 'crm.import', 'crm.export', 'crm.gdpr',
    ])
        ->and(Permissions::all())->toContain(...CrmPermissions::all())
        ->and(Permissions::defaultsFor(Role::AdminOrganisme))->toContain(...CrmPermissions::all())
        ->and(CrmPermissions::defaultsFor(Role::Direction))->toBe(['crm.view', 'crm.export', 'crm.gdpr'])
        ->and(CrmPermissions::defaultsFor(Role::Commercial))->toBe(['crm.view', 'crm.create', 'crm.update', 'crm.delete', 'crm.merge', 'crm.import', 'crm.export'])
        ->and(CrmPermissions::defaultsFor(Role::ChargeRelations))->toBe(CrmPermissions::defaultsFor(Role::Commercial))
        ->and(CrmPermissions::defaultsFor(Role::Assistant))->toBe(['crm.view', 'crm.create', 'crm.update'])
        ->and(CrmPermissions::defaultsFor(Role::ResponsableDas))->toBe(['crm.view', 'crm.export']);
});

it('gives a new tenant the full matrix, CRM included', function () {
    $tenant = Tenant::factory()->create();
    $matrix = matrixOf($tenant);

    expect($matrix['admin_organisme'])->toBe(collect(Permissions::all())->sort()->values()->all())
        ->and($matrix['direction'])->toBe(['audit.view', 'crm.export', 'crm.gdpr', 'crm.view', 'exports.run'])
        ->and($matrix['assistant'])->toBe(['crm.create', 'crm.update', 'crm.view'])
        ->and($matrix['responsable_das'])->toBe(['crm.export', 'crm.view']);
});

it('sync adds new default permissions to existing tenants once', function () {
    $tenant = Tenant::factory()->create();
    forgetCrmOffers($tenant);
    PermissionModel::query()->where('name', 'like', 'crm.%')->delete();

    expect(matrixOf($tenant)['commercial'])->toBe([]);

    $this->artisan('tms:permissions:sync')->assertSuccessful();

    expect(PermissionModel::query()->where('name', 'like', 'crm.%')->count())->toBe(8)
        ->and(matrixOf($tenant)['commercial'])->toBe(['crm.create', 'crm.delete', 'crm.export', 'crm.import', 'crm.merge', 'crm.update', 'crm.view'])
        ->and(matrixOf($tenant)['direction'])->toBe(['audit.view', 'crm.export', 'crm.gdpr', 'crm.view', 'exports.run']);

    // Idempotent.
    $before = matrixOf($tenant);
    $rows = app(TenantContext::class)->run($tenant, fn () => DB::table('role_permission_defaults_applied')->count());
    $this->artisan('tms:permissions:sync')->assertSuccessful();

    expect(matrixOf($tenant))->toBe($before)
        ->and(app(TenantContext::class)->run($tenant, fn () => DB::table('role_permission_defaults_applied')->count()))->toBe($rows);
});

it('sync never re-adds a permission an admin removed', function () {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->run($tenant, function () {
        RoleModel::findByName('commercial')->revokePermissionTo('crm.delete');
        RoleModel::findByName('direction')->revokePermissionTo('exports.run');
    });

    PermissionSync::run();

    expect(matrixOf($tenant)['commercial'])->not->toContain('crm.delete')
        ->and(matrixOf($tenant)['direction'])->not->toContain('exports.run');
});

it('sync reaches suspended tenants and leaves no context behind', function () {
    $tenant = Tenant::factory()->suspended()->create();
    forgetCrmOffers($tenant);

    PermissionSync::run();

    expect(matrixOf($tenant)['assistant'])->toBe(['crm.create', 'crm.update', 'crm.view'])
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
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => collect($p)->contains('crm.view') && ! collect($p)->contains('crm.gdpr')));
});
