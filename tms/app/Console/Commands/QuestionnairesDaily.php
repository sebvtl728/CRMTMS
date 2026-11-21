<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Questionnaires\Campaigns\DailySurveys;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled every morning at 07:00 Paris time (routes/console.php): in each
 * organisme, the hot questionnaires nobody launched and the cold ones due
 * today. One organisme failing does not stop the others.
 */
class QuestionnairesDaily extends Command
{
    protected $signature = 'tms:questionnaires:daily';

    protected $description = 'Lance les questionnaires de satisfaction dus (à chaud non lancés, à froid)';

    public function handle(): int
    {
        $hot = $cold = 0;
        $today = CarbonImmutable::now('Europe/Paris');
        $failed = ForEachTenant::run(function () use (&$hot, &$cold, $today): void {
            $done = app(DailySurveys::class)->run($today);
            $hot += $done['hot'];
            $cold += $done['cold'];
        });

        $this->info("Questionnaires lancés : {$hot} à chaud, {$cold} à froid.");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
