<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Connectors\ConnectorsServiceProvider;
use App\Modules\Core\Access\AccessServiceProvider;
use App\Modules\Core\Audit\AuditServiceProvider;
use App\Modules\Core\Mail\MailServiceProvider;
use App\Modules\Core\Tenancy\TenancyServiceProvider;
use App\Modules\Core\Users\UsersServiceProvider;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Sessions\SessionsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    RateLimitServiceProvider::class,
    TenancyServiceProvider::class,
    UsersServiceProvider::class,
    AccessServiceProvider::class,
    AuditServiceProvider::class,
    MailServiceProvider::class,
    ConnectorsServiceProvider::class,
    CrmServiceProvider::class,
    CatalogueServiceProvider::class,
    SessionsServiceProvider::class,
];
