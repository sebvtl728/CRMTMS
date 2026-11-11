<?php

use App\Modules\Core\Tenancy\TenancyServiceProvider;
use App\Modules\Core\Users\UsersServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TenancyServiceProvider::class,
    UsersServiceProvider::class,
];
