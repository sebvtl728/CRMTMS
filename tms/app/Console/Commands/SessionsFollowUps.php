<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Sessions\FollowUps\Reactivations;
use App\Modules\Sessions\FollowUps\TrainerFollowUps;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled every morning at 06:30 Paris time (routes/console.php): in each
 * organisme, the follow-up calls due today (lot 1e: the trainer's call
 * after a training, the reactivation call after a dossier). One organisme failing does not stop the others.
 */
class SessionsFollowUps extends Command
{
    protected $signature = 'tms:sessions:follow-ups';

    protected $description = 'Crée les actions de suivi dues (suivi formateur, réactivation des clients)';

    public function handle(): int
    {
        $total = 0;
        $today = CarbonImmutable::now('Europe/Paris');
        $failed = ForEachTenant::run(function () use (&$total, $today): void {
            $total += app(TrainerFollowUps::class)->run($today);
            $total += app(Reactivations::class)->run($today);
        });

        $this->info("Actions de suivi créées : {$total}.");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
