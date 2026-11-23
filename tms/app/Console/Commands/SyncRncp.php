<?php

namespace App\Console\Commands;

use App\Modules\Catalogue\Rncp\RncpSync;
use Illuminate\Console\Command;
use Throwable;

/** tms:rncp:sync — loads the latest RNCP open data export (weekly, or by hand). */
class SyncRncp extends Command
{
    protected $signature = 'tms:rncp:sync';

    protected $description = 'Charge le répertoire RNCP de France compétences (données ouvertes)';

    public function handle(RncpSync $sync): int
    {
        try {
            $count = $sync->run();
        } catch (Throwable $e) {
            $this->error('Répertoire RNCP non mis à jour : '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$count} certifications RNCP chargées.");

        return self::SUCCESS;
    }
}
