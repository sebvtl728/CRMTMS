<?php

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ProbeItem;
use Tests\Fixtures\UnscopedProbeItem;

/**
 * Concrete Eloquent models declared under app/Modules (discovered from the
 * files, then loaded through the autoloader).
 *
 * @return list<class-string<Model>>
 */
function moduleModels(): array
{
    $root = app_path('Modules');
    $models = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'App\\Modules\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $models[] = $class;
        }
    }

    return $models;
}

/**
 * @param  class-string  $class
 */
function usesTrait(string $class, string $trait): bool
{
    return in_array($trait, class_uses_recursive($class), true);
}

/**
 * Models whose table has a tenant_id column but which lack BelongsToTenant.
 * Platform tables (tms.tenancy.unscoped_tables, e.g. tenant_domains whose
 * tenant_id is a plain foreign key) are exempt, as in TenancyArchTest.
 *
 * @param  list<class-string<Model>>  $models
 * @return list<string>
 */
function tenantModelViolations(array $models): array
{
    $violations = [];
    $platform = config('tms.tenancy.unscoped_tables');

    foreach ($models as $class) {
        $table = (new $class)->getTable();

        if (! in_array($table, $platform, true) && Schema::hasColumn($table, 'tenant_id') && ! usesTrait($class, BelongsToTenant::class)) {
            $violations[] = "{$class} ({$table}) sans BelongsToTenant";
        }
    }

    return $violations;
}

it('discovers the module models', function () {
    expect(moduleModels())->toContain(User::class, Tenant::class);
});

it('every model of a tenant table uses BelongsToTenant', function () {
    expect(tenantModelViolations(moduleModels()))->toBe([]);
});

it('flags a model of a tenant table without BelongsToTenant', function () {
    expect(tenantModelViolations([ProbeItem::class, UnscopedProbeItem::class]))
        ->toBe([UnscopedProbeItem::class.' (probe_items) sans BelongsToTenant']);
});

/**
 * Models of $namespace that do not use Auditable.
 *
 * @param  list<class-string<Model>>  $models
 * @return list<class-string<Model>>
 */
function unauditedModels(array $models, string $namespace): array
{
    return array_values(array_filter($models, fn (string $class) => str_starts_with($class, $namespace)
        && ! usesTrait($class, Auditable::class)));
}

it('every CRM model is audited', function () {
    expect(unauditedModels(moduleModels(), 'App\\Modules\\Crm\\'))->toBe([]);
});

it('flags a model without Auditable in an audited namespace', function () {
    expect(unauditedModels([ProbeItem::class, User::class], 'Tests\\Fixtures\\'))->toBe([ProbeItem::class]);
});
