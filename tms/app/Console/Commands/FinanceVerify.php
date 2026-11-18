<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Finance\Integrity;
use Illuminate\Console\Command;

/**
 * Recomputes the chain of the validated invoices and credit notes of every
 * organisme (spec 0.9 § 4.4): exit 1 with the first broken number when a
 * piece, a line or the journal was changed outside the application.
 */
class FinanceVerify extends Command
{
    protected $signature = 'tms:finance:verify';

    protected $description = 'Vérifie l\'intégrité des factures et avoirs validés (chaîne des empreintes), pour chaque organisme';

    public function handle(): int
    {
        $broken = false;
        $failed = ForEachTenant::run(function (Tenant $tenant) use (&$broken): void {
            $result = Integrity::verify();
            if ($result['broken'] !== null) {
                $broken = true;
                $this->error("Organisme « {$tenant->name} » : intégrité rompue à la pièce {$result['broken']}.");

                return;
            }
            $this->info("Organisme « {$tenant->name} » : intégrité vérifiée ({$result['count']} pièce".($result['count'] > 1 ? 's' : '').').');
        }, includeSuspended: true);

        return $broken || $failed !== [] ? self::FAILURE : self::SUCCESS;
    }
}
