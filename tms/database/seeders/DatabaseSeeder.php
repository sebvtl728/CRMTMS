<?php

namespace Database\Seeders;

use App\Modules\Core\Platform\TenantProvisioner;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds a demo organisme (default tenant of TMS_MODE=single) with its
     * roles, default company and the CAP FORMATION reference lists. Goes
     * through TenantProvisioner because every business table is under RLS and
     * needs a tenant context.
     *
     * No user is seeded (no default password): accounts are created by
     * invitation or by the installer (tms:install).
     *
     * Idempotent: an existing default tenant only gets its missing references.
     *
     * `migrate:fresh --database=pgsql_owner --seed` makes the owner connection
     * the default one, but the tenant setting (app.tenant_id) lives on the
     * runtime connection: seed through that one, as the application does.
     */
    public function run(TenantProvisioner $provisioner): void
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection((string) config('tms.tenancy.connection'));

        // A fresh database must not meet the permission list cached (Redis) by the previous one.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            $this->seed($provisioner);
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function seed(TenantProvisioner $provisioner): void
    {
        $slug = (string) config('tms.default_tenant');
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant !== null) {
            CapFormationReferenceSeeder::run($tenant);

            return;
        }

        $provisioner->create(
            (string) config('app.name'),
            $slug,
            [],
            fn (Tenant $created) => CapFormationReferenceSeeder::run($created),
        );
    }
}
