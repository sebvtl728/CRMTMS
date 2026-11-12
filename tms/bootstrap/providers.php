<?php

use App\Modules\Connectors\ConnectorsServiceProvider;
use App\Modules\Core\Access\AccessServiceProvider;
use App\Modules\Core\Audit\AuditServiceProvider;
use App\Modules\Core\Tenancy\TenancyServiceProvider;
use App\Modules\Core\Users\UsersServiceProvider;
use App\Modules\Crm\CrmServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TenancyServiceProvider::class,
    UsersServiceProvider::class,
    AccessServiceProvider::class,
    AuditServiceProvider::class,
    ConnectorsServiceProvider::class,
    CrmServiceProvider::class,
];
