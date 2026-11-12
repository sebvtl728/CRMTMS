<?php

namespace App\Modules\Core\Tenancy;

use Closure;

/**
 * Job middleware: runs the job inside the tenant it captured (TenantAwareJob),
 * then restores the worker's previous context.
 */
class SetTenantForJob
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! property_exists($job, 'tenantId') || ! isset($job->tenantId) || ! is_string($job->tenantId)) {
            throw new MissingTenantContext('Tâche sans organisme : '.$job::class);
        }

        $tenant = Tenant::query()->findOrFail($job->tenantId);

        return app(TenantContext::class)->run($tenant, fn () => $next($job));
    }
}
