<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleMatrix;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\RoleSeeder;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Tenancy\QueueTenancy;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

const SCOPED = 'crm.test.view';
const UNSCOPED = 'crm.test.other';

beforeEach(function () {
    // Fake module: commercial gets both, scoped one defaults to « own ».
    Permissions::registerModule(
        'zz_scopes_test',
        [SCOPED, UNSCOPED],
        fn (Role $role) => $role === Role::Commercial ? [SCOPED, UNSCOPED] : [],
        [SCOPED => ['Voir (test)', 'Test.'], UNSCOPED => ['Autre (test)', 'Test.']],
        'Test',
        [SCOPED],
        fn (Role $role) => $role === Role::Commercial ? [SCOPED => AccessScope::Own] : [],
    );
    Scopes::forget();
});

afterEach(function () {
    Permissions::unregisterModule('zz_scopes_test');
    Scopes::forget();
});

/** Grants the fake permissions in the current tenant by the default matrix, then drops the default scope row. */
function grantFakeDefaults(bool $keepScopeRows = false): void
{
    PermissionSync::forTenant(tenant());
    if (! $keepScopeRows) {
        DB::table('role_permission_scopes')->delete();
    }
    Scopes::forget();
}

function commercialRole(): RoleModel
{
    return RoleModel::query()->where('name', 'commercial')->sole();
}

it('treats a scoped permission without row as all', function () {
    grantFakeDefaults();

    expect(Scopes::of(commercial(), SCOPED))->toBe(AccessScope::All);
});

it('gives null when the permission is not granted', function () {
    grantFakeDefaults();

    expect(Scopes::of(assistant(), SCOPED))->toBeNull();
});

it('stores a scope and journals the change with the previous value', function () {
    grantFakeDefaults();
    $user = commercial();

    app(RoleMatrix::class)->setScope(RoleModel::forSystem(Role::Commercial), SCOPED, AccessScope::Das);

    expect(Scopes::of($user, SCOPED))->toBe(AccessScope::Das);
    $log = AuditLog::query()->where('event', 'roles.scope_changed')->sole();
    expect($log->new_values)->toEqual(['permission' => SCOPED, 'scope' => 'das', 'role' => 'commercial', 'role_label' => 'Commercial'])
        ->and($log->old_values)->toEqual(['permission' => SCOPED, 'scope' => 'all']);
});

it('never changes the scope of the administrator role', function () {
    grantFakeDefaults();

    app(RoleMatrix::class)->setScope(RoleModel::forSystem(Role::AdminOrganisme), SCOPED, AccessScope::Own);
})->throws(AccessDeniedHttpException::class);

it('refuses a scope on a non scoped permission', function () {
    grantFakeDefaults();

    app(RoleMatrix::class)->setScope(RoleModel::forSystem(Role::Commercial), UNSCOPED, AccessScope::Own);
})->throws(AccessDeniedHttpException::class);

it('asks to grant the right first when the permission is not granted', function () {
    grantFakeDefaults();

    try {
        app(RoleMatrix::class)->setScope(RoleModel::forSystem(Role::Assistant), SCOPED, AccessScope::Own);
        $this->fail('422 attendu');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422)->and($e->getMessage())->toBe("Accordez d'abord ce droit.");
    }
});

it('drops the scope row when the permission is revoked', function () {
    grantFakeDefaults();
    $matrix = app(RoleMatrix::class);
    $matrix->setScope(RoleModel::forSystem(Role::Commercial), SCOPED, AccessScope::Own);
    expect(DB::table('role_permission_scopes')->where('permission', SCOPED)->count())->toBe(1);

    $matrix->set(RoleModel::forSystem(Role::Commercial), SCOPED, false);

    expect(DB::table('role_permission_scopes')->count())->toBe(0)
        ->and(Scopes::of(commercial(), SCOPED))->toBeNull();
});

it('keeps scopes isolated between organismes', function () {
    grantFakeDefaults();
    $other = Tenant::factory()->create();
    PermissionSync::forTenant($other);
    app(TenantContext::class)->run($other, fn () => DB::table('role_permission_scopes')->delete());
    $mine = commercial();
    $theirs = userIn($other, []);
    app(TenantContext::class)->run($other, fn () => $theirs->assignPrimaryRole(Role::Commercial));

    app(RoleMatrix::class)->setScope(RoleModel::forSystem(Role::Commercial), SCOPED, AccessScope::Das);
    app(TenantContext::class)->run($other, fn () => app(RoleMatrix::class)->setScope(RoleModel::forSystem(Role::Commercial), SCOPED, AccessScope::Own));

    expect(Scopes::of($mine, SCOPED))->toBe(AccessScope::Das);
    app(TenantContext::class)->run($other, function () use ($theirs) {
        expect(Scopes::of($theirs->fresh(), SCOPED))->toBe(AccessScope::Own);
    });
    expect(DB::table('role_permission_scopes')->count())->toBe(1);
});

it('reloads the scopes at each queued job', function () {
    grantFakeDefaults();
    $user = commercial();
    expect(Scopes::of($user, SCOPED))->toBe(AccessScope::All);

    // Changed by another process (the web): this worker still has its memory.
    DB::table('role_permission_scopes')->insert(['tenant_id' => tenant()->id, 'role_id' => commercialRole()->id, 'permission' => SCOPED, 'scope' => 'own']);
    expect(Scopes::of($user, SCOPED))->toBe(AccessScope::All); // stale

    event(new JobProcessing('redis', Mockery::mock(Job::class, ['payload' => [QueueTenancy::PAYLOAD_KEY => tenant()->id], 'getConnectionName' => 'redis', 'getQueue' => 'default'])->shouldIgnoreMissing()));

    expect(Scopes::of($user, SCOPED))->toBe(AccessScope::Own);
});

it('grants the default scope once with the default right', function () {
    PermissionSync::forTenant(tenant());

    $row = fn () => DB::table('role_permission_scopes')->where('permission', SCOPED)->value('scope');
    expect($row())->toBe('own');

    app(RoleMatrix::class)->setScope(RoleModel::forSystem(Role::Commercial), SCOPED, AccessScope::Das);
    PermissionSync::forTenant(tenant());
    RoleSeeder::seedFor(tenant());

    expect($row())->toBe('das');
});

it('writes the default scopes again on reset and tells which scopes a role uses', function () {
    PermissionSync::forTenant(tenant());
    $matrix = app(RoleMatrix::class);
    $matrix->setScope(RoleModel::forSystem(Role::Commercial), SCOPED, AccessScope::Das);
    expect(Scopes::roleUses(commercialRole(), AccessScope::Das))->toBeTrue()
        ->and(DB::table('role_permission_scopes')->where('role_id', commercialRole()->id)->where('permission', SCOPED)->value('scope'))->toBe('das');

    $matrix->reset(RoleModel::forSystem(Role::Commercial));

    expect(DB::table('role_permission_scopes')->where('permission', SCOPED)->value('scope'))->toBe('own')
        ->and(Scopes::roleUses(commercialRole(), AccessScope::Own))->toBeTrue()
        // The CRM rights of the commercial (no row) are « all »; none is « das » any more.
        ->and(Scopes::roleUses(commercialRole(), AccessScope::All))->toBeTrue()
        ->and(Scopes::roleUses(commercialRole(), AccessScope::Das))->toBeFalse();
});

it('exposes the scope options in French', function () {
    expect(AccessScope::options())->toBe([
        ['value' => 'own', 'label' => 'Ses fiches'],
        ['value' => 'das', 'label' => 'Ses DAS'],
        ['value' => 'all', 'label' => "Tout l'organisme"],
    ])->and(Permissions::isScoped(SCOPED))->toBeTrue()
        ->and(Permissions::isScoped(UNSCOPED))->toBeFalse()
        ->and(Permissions::defaultScopesFor(Role::Commercial))->toBe(['crm.revenue.view' => AccessScope::Own, SCOPED => AccessScope::Own]);
});

it('keeps the default scope when a right is revoked then granted again', function () {
    PermissionSync::forTenant(tenant());
    $matrix = app(RoleMatrix::class);
    $matrix->setScope(RoleModel::forSystem(Role::Commercial), SCOPED, AccessScope::Das);

    $matrix->set(RoleModel::forSystem(Role::Commercial), SCOPED, false);
    $matrix->set(RoleModel::forSystem(Role::Commercial), SCOPED, true);

    expect(Scopes::of(commercial(), SCOPED))->toBe(AccessScope::Own)
        ->and(DB::table('role_permission_scopes')->where('permission', SCOPED)->value('scope'))->toBe('own');
});

it('forgets the memoised scope when the role of a user changes', function () {
    PermissionSync::forTenant(tenant());
    $user = commercial();
    expect(Scopes::of($user, SCOPED))->toBe(AccessScope::Own);

    $user->assignPrimaryRole(Role::Assistant);

    expect(Scopes::of($user, SCOPED))->toBeNull();
});
