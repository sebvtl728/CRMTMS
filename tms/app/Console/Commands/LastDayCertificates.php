<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Documents\Automation\CertificateAutomation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class LastDayCertificates extends Command
{
    protected $signature = 'tms:documents:last-day-certificates';

    protected $description = 'Envoie les attestations et certificats de réalisation le dernier jour de formation (13 h 30)';

    public function handle(): int
    {
        $total = 0;
        $now = CarbonImmutable::now('Europe/Paris');
        $failed = ForEachTenant::run(function () use (&$total, $now): void {
            $total += app(CertificateAutomation::class)->runLastDay($now);
        });
        $this->info("Attestations du dernier jour : {$total}.");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
