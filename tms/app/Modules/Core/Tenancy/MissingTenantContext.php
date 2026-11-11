<?php

namespace App\Modules\Core\Tenancy;

use RuntimeException;

/**
 * Thrown when code needs the current tenant (TenantContext::id(), creating a
 * tenant-owned model, building a tenant-aware job) and none is set.
 */
class MissingTenantContext extends RuntimeException
{
    public function __construct(string $message = 'Aucun organisme (tenant) défini pour cette opération.')
    {
        parent::__construct($message);
    }
}
