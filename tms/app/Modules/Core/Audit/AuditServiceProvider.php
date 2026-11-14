<?php

namespace App\Modules\Core\Audit;

use App\Modules\Core\Access\PrimaryRoleChanged;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        MorphAliases::register([
            'tenant' => Tenant::class,
            'user' => User::class,
            'invitation' => Invitation::class,
            'company' => Company::class,
            'reference_item' => ReferenceItem::class,
            'role' => RoleModel::class,
        ]);
    }

    public function boot(): void
    {
        Event::subscribe(AuthEventSubscriber::class);
        Event::listen(PrimaryRoleChanged::class, RecordRoleChange::class);
    }
}
