<?php

namespace Tests\Fixtures;

use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Not tenant-aware: records the context it runs in. */
class ContextSnapshotJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static bool $ran = false;

    public static ?string $seenTenantId = null;

    public static ?string $seenSetting = null;

    public function handle(TenantContext $context): void
    {
        static::$ran = true;
        static::$seenTenantId = $context->current()?->id;
        static::$seenSetting = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v;
    }
}
