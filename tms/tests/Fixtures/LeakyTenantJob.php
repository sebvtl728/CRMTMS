<?php

namespace Tests\Fixtures;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** Not tenant-aware: switches the context itself and never restores it. */
class LeakyTenantJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $targetTenantId) {}

    public function handle(TenantContext $context): void
    {
        $context->set(Tenant::query()->findOrFail($this->targetTenantId));
    }
}
