<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Crm\Quotes\QuoteTransitions;
use Illuminate\Console\Command;

/**
 * Scheduled daily at 00:05 Paris time (routes/console.php): the sent or
 * reminded quotes whose validity ended the day before are expired, in every
 * organisme (one failing does not stop the others).
 */
class ExpireQuotes extends Command
{
    protected $signature = 'tms:crm:expire-quotes';

    protected $description = 'Passe en « Expiré » les devis envoyés dont la date de validité est dépassée, pour chaque organisme';

    public function handle(): int
    {
        $total = 0;
        $failed = ForEachTenant::run(function () use (&$total): void {
            $total += QuoteTransitions::expireDue();
        });

        $this->info("Devis expirés : {$total}.");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
