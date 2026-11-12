<?php

namespace App\Modules\Core\Audit;

use App\Modules\Core\Access\PrimaryRoleChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::subscribe(AuthEventSubscriber::class);
        Event::listen(PrimaryRoleChanged::class, RecordRoleChange::class);
    }
}
