<?php

namespace App\Modules\Core\Tenancy;

/**
 * For every queued job touching tenant data. The tenant active when the job
 * is built is captured (serialized with the job) and re-established around
 * handle() by SetTenantForJob, whatever context the worker is in.
 *
 * Jobs defining their own constructor must call $this->captureTenant();
 * jobs defining their own middleware() must include new SetTenantForJob.
 * A job without captured tenant fails (MissingTenantContext), never runs unscoped.
 */
trait TenantAwareJob
{
    public string $tenantId;

    public function __construct()
    {
        $this->captureTenant();
    }

    /**
     * @throws MissingTenantContext
     */
    protected function captureTenant(): void
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetTenantForJob];
    }
}
