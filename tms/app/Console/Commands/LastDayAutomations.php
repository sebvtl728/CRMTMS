<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Documents\Automation\CertificateAutomation;
use App\Modules\Questionnaires\Campaigns\DailySurveys;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class LastDayAutomations extends Command
{
    protected $signature = 'tms:automation:last-day';

    protected $description = 'Le dernier jour de formation à 13 h 30 : questionnaire à chaud, attestations et certificats de réalisation';

    public function handle(): int
    {
        $surveys = 0;
        $certificates = 0;
        $now = CarbonImmutable::now('Europe/Paris');
        $failed = ForEachTenant::run(function () use (&$surveys, &$certificates, $now): void {
            $surveys += app(DailySurveys::class)->runLastDay($now);
            $certificates += app(CertificateAutomation::class)->runLastDay($now);
        });
        $this->info("Dernier jour : {$surveys} questionnaire(s) à chaud, {$certificates} attestation(s) et certificat(s).");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
