<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Crm\Timeline\AttachmentPurge;
use Illuminate\Console\Command;

/**
 * Scheduled daily (routes/console.php).
 */
class CrmPurgeAttachments extends Command
{
    protected $signature = 'tms:crm:purge-attachments';

    protected $description = 'Supprime les fichiers joints des fiches en corbeille depuis plus de '.AttachmentPurge::TRASHED_MONTHS.' mois, pour chaque organisme';

    public function handle(): int
    {
        $total = 0;
        $failed = ForEachTenant::run(function () use (&$total): void {
            $total += AttachmentPurge::purgeTrashedFiches();
        }, includeSuspended: true);

        $this->info("Fichiers supprimés : {$total}.");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
