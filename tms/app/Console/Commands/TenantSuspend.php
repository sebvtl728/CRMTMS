<?php

namespace App\Console\Commands;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantStatus;
use Illuminate\Console\Command;

class TenantSuspend extends Command
{
    protected $signature = 'tms:tenant:suspend {slug : Identifiant de l\'organisme} {--reactivate : Réactive l\'organisme au lieu de le suspendre}';

    protected $description = 'Suspend (ou réactive) un organisme';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->error('Organisme introuvable.');

            return self::FAILURE;
        }

        $reactivate = (bool) $this->option('reactivate');
        $tenant->forceFill(['status' => $reactivate ? TenantStatus::Active : TenantStatus::Suspended])->save();

        $this->info($reactivate
            ? "Organisme « {$tenant->slug} » réactivé."
            : "Organisme « {$tenant->slug} » suspendu.");

        return self::SUCCESS;
    }
}
