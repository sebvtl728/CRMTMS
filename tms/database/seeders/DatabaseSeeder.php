<?php

namespace Database\Seeders;

use App\Modules\Core\Tenancy\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * No user is seeded (no default password): accounts are created by
     * invitation or by the installer (tms:install).
     */
    public function run(): void
    {
        // Default tenant for single mode (TMS_MODE=single).
        Tenant::query()->firstOrCreate(
            ['slug' => config('tms.default_tenant')],
            ['name' => config('app.name')],
        );
    }
}
