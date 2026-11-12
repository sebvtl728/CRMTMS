<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Application-side half of the isolation (RLS is the database-side half):
 * restricts queries to the current tenant, and to nothing without a tenant.
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class)->current();

        if ($tenant === null) {
            $builder->whereRaw('false');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenant->id);
    }
}
