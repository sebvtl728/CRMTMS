<?php

namespace App\Console\Commands;

use App\Modules\Admissions\Gdpr\PurgeStaleCandidatures as Purge;
use App\Modules\Core\Tenancy\ForEachTenant;
use Illuminate\Console\Command;

/**
 * Scheduled daily at 03:50 Paris time (routes/console.php): the rejected and
 * withdrawn candidatures untouched for two years are anonymised, in every
 * organisme (one failing does not stop the others).
 */
class PurgeStaleCandidatures extends Command
{
    protected $signature = 'tms:admissions:purge';

    protected $description = 'Anonymise les candidatures non retenues ou désistées sans modification depuis 2 ans, pour chaque organisme';

    public function handle(): int
    {
        $total = 0;
        $failed = ForEachTenant::run(function () use (&$total): void {
            $total += app(Purge::class)->run();
        });

        $this->info("Candidatures anonymisées : {$total}.");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
