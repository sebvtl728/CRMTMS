<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportFiles;
use Illuminate\Console\Command;

/**
 * Scheduled daily (routes/console.php).
 */
class CrmPurgeImports extends Command
{
    protected $signature = 'tms:crm:purge-imports';

    protected $description = 'Supprime les fichiers importés (et leurs valeurs brutes) de plus de '.ImportBatch::RETENTION_DAYS.' jours, pour chaque organisme';

    public function handle(): int
    {
        $total = 0;
        ForEachTenant::run(function () use (&$total): void {
            $total += ImportFiles::purgeExpired();
        }, includeSuspended: true);

        $this->info("Imports purgés : {$total}.");

        return self::SUCCESS;
    }
}
