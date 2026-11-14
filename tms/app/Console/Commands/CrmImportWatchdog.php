<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Crm\Import\ImportWatchdog;
use Illuminate\Console\Command;

/**
 * Scheduled every 10 minutes (routes/console.php).
 */
class CrmImportWatchdog extends Command
{
    protected $signature = 'tms:crm:import-watchdog';

    protected $description = 'Relance une fois les imports sans avancement depuis 15 minutes, puis les marque échoués, pour chaque organisme';

    public function handle(ImportWatchdog $watchdog): int
    {
        $totals = ['resumed' => 0, 'failed' => 0];
        $failed = ForEachTenant::run(function () use ($watchdog, &$totals): void {
            foreach ($watchdog->run() as $key => $count) {
                $totals[$key] += $count;
            }
        });

        $this->info("Imports relancés : {$totals['resumed']}, marqués échoués : {$totals['failed']}.");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
