<?php

namespace App\Modules\Core\Tenancy;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
