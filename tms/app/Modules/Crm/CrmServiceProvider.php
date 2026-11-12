<?php

namespace App\Modules\Crm;

use App\Modules\Core\Access\Permissions as CorePermissions;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In register(): the catalogue must be complete before
        // AccessServiceProvider::boot() defines one gate per permission.
        CorePermissions::registerModule('crm', Permissions::all(), Permissions::defaultsFor(...));
    }
}
