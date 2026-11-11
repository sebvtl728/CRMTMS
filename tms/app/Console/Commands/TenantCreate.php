<?php

namespace App\Console\Commands;

use App\Modules\Core\Platform\TenantProvisioner;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TenantCreate extends Command
{
    protected $signature = 'tms:tenant:create {name : Nom de l\'organisme} {--slug= : Identifiant (par défaut dérivé du nom)} {--domain=* : Nom de domaine (répétable)} {--vat-exempt : Société par défaut exonérée de TVA}';

    protected $description = 'Crée un organisme (tenant) avec ses domaines, ses rôles et sa société par défaut';

    public function handle(TenantProvisioner $provisioner): int
    {
        try {
            [$tenant] = $provisioner->create(
                (string) $this->argument('name'),
                $this->option('slug') ?: null,
                (array) $this->option('domain'),
                null,
                (bool) $this->option('vat-exempt'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Organisme créé : {$tenant->name} ({$tenant->slug})");
        $this->line($tenant->id);

        return self::SUCCESS;
    }
}
