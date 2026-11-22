<?php

namespace App\Modules\Core\Automation;

use App\Modules\Core\Tenancy\TenantAwareJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/** « Exécuter maintenant »: the automations of the morning, same rules and windows, in the organisme of the request. */
class RunAutomationsNow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public function __construct()
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        DailyAutomations::runAll(CarbonImmutable::now('Europe/Paris'));
    }
}
