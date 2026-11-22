<?php

namespace App\Console\Commands;

use App\Modules\Core\Automation\DailyAutomations;
use App\Modules\Core\Tenancy\ForEachTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AutomationDaily extends Command
{
    protected $signature = 'tms:automation:daily';

    protected $description = 'Lance les automatismes du jour (convocations, rappels de la veille, relances de signature)';

    public function handle(): int
    {
        $totals = [];
        $today = CarbonImmutable::now('Europe/Paris');
        $failed = ForEachTenant::run(function () use (&$totals, $today): void {
            foreach (DailyAutomations::runAll($today) as $key => $count) {
                $totals[$key] = ($totals[$key] ?? 0) + ($count ?? 0);
            }
        });
        $this->info('Automatismes : '.($totals === [] ? 'aucun' : implode(', ', array_map(fn ($k, $n) => "{$k} {$n}", array_keys($totals), $totals))).'.');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
