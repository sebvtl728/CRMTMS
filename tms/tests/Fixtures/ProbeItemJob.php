<?php

namespace Tests\Fixtures;

use App\Modules\Core\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/** Canonical "job carrying a tenant model" pattern (new SendInvoice($invoice)). */
class ProbeItemJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels, TenantAwareJob;

    public static ?string $seenLabel = null;

    public function __construct(public ProbeItem $item)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        static::$seenLabel = $this->item->label;
    }
}
