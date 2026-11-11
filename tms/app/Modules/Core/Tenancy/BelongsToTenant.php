<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Mandatory on every business model (table with tenant_id + Rls::enable).
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->id(); // throws MissingTenantContext

            $assigned = $model->getAttribute('tenant_id');

            if ($assigned === null) {
                $model->setAttribute('tenant_id', $tenantId);
            } elseif ($assigned !== $tenantId) {
                throw new LogicException('Impossible de créer une donnée pour un autre organisme que l\'organisme courant.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('Une donnée ne peut pas changer d\'organisme.');
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
