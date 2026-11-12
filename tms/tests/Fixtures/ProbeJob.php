<?php

namespace Tests\Fixtures;

use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public static ?int $seenCount = null;

    public static ?int $seenRawCount = null;

    public static ?string $seenTenantId = null;

    public function handle(TenantContext $context): void
    {
        static::$seenCount = ProbeItem::count();
        static::$seenRawCount = DB::table('probe_items')->count();
        static::$seenTenantId = $context->id();
    }
}
