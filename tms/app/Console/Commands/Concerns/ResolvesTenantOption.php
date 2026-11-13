<?php

namespace App\Console\Commands\Concerns;

use App\Modules\Core\Tenancy\Tenant;

/**
 * --tenant=slug option of the operator commands. In single mode it defaults to
 * TMS_DEFAULT_TENANT; in multi mode it is required (a default would silently act
 * on whichever organisme happens to carry that slug).
 */
trait ResolvesTenantOption
{
    private function tenantFromOption(): ?Tenant
    {
        $given = (string) $this->option('tenant');
        if ($given === '' && config('tms.mode') === 'multi') {
            $this->error('Mode multi-organismes : précisez l\'organisme avec --tenant=identifiant.');

            return null;
        }

        $slug = $given ?: (string) config('tms.default_tenant');
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Organisme introuvable : « {$slug} » (option --tenant=identifiant).");
        }

        return $tenant;
    }
}
