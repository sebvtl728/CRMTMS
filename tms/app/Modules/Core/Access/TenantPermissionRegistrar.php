<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * spatie caches "every permission with its roles". Under RLS that list only
 * holds the current tenant's roles, so the cache must be per tenant: one
 * cache key per tenant, and the in-memory copy is dropped whenever the tenant
 * context changes (long-lived workers, TenantContext::run()).
 */
class TenantPermissionRegistrar extends PermissionRegistrar
{
    private ?string $loadedFor = null;

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, Model>
     */
    public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        $this->followTenant();

        return parent::getPermissions($params, $onlyOne);
    }

    public function forgetCachedPermissions(): bool
    {
        $this->followTenant();

        return parent::forgetCachedPermissions();
    }

    private function followTenant(): void
    {
        $tenant = app(TenantContext::class)->current()->id ?? 'none';

        if ($tenant !== $this->loadedFor) {
            $this->cacheKey = config('permission.cache.key').'.'.$tenant;
            $this->clearPermissionsCollection();
            $this->loadedFor = $tenant;
        }
    }
}
