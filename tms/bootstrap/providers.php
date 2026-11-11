<?php

use App\Modules\Core\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TenancyServiceProvider::class,
];
