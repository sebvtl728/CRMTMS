<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Crm\Gdpr\AnonymiseExpired;
use Illuminate\Console\Command;

/**
 * Scheduled every night (routes/console.php): one AnonymiseExpired job per
 * organisme (suspended ones too: their data is still held).
 */
class CrmGdprRetention extends Command
{
    protected $signature = 'tms:crm:gdpr-retention';

    protected $description = 'Liste les personnes dont la durée de conservation est dépassée et anonymise celles listées depuis 30 jours, pour chaque organisme';

    public function handle(): int
    {
        $count = 0;
        ForEachTenant::run(function () use (&$count): void {
            AnonymiseExpired::dispatch();
            $count++;
        }, includeSuspended: true);

        $this->info("Organismes traités : {$count}.");

        return self::SUCCESS;
    }
}
