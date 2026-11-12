<?php

namespace App\Console\Commands\Concerns;

use App\Modules\Core\Tenancy\Tenant;

/**
 * --tenant=slug option of the operator commands (default: TMS_DEFAULT_TENANT).
 */
trait ResolvesTenantOption
{
    private function tenantFromOption(): ?Tenant
    {
        $slug = (string) ($this->option('tenant') ?: config('tms.default_tenant'));
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Organisme introuvable : « {$slug} » (option --tenant=identifiant).");
        }

        return $tenant;
    }
}
