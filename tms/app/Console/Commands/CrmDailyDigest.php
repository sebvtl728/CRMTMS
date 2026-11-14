<?php

namespace App\Console\Commands;

use App\Modules\Crm\Activities\DailyDigest;
use Illuminate\Console\Command;

/**
 * Scheduled every morning at 07:30 Paris time (routes/console.php): queues
 * the daily summary of every user who asked for it, organisme by organisme.
 */
class CrmDailyDigest extends Command
{
    protected $signature = 'tms:crm:daily-digest';

    protected $description = 'Envoie le récapitulatif quotidien des actions à faire aux utilisateurs qui l\'ont demandé';

    public function handle(): int
    {
        $count = DailyDigest::dispatchAll();
        $this->info("Récapitulatifs mis en file : {$count}.");

        return self::SUCCESS;
    }
}
