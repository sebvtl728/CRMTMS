<?php

namespace App\Console\Commands;

use App\Modules\Core\Access\PermissionSync;
use Illuminate\Console\Command;

class PermissionsSync extends Command
{
    protected $signature = 'tms:permissions:sync';

    protected $description = 'Met à jour les permissions et la matrice par défaut des rôles de tous les organismes (sans rétablir une permission retirée)';

    public function handle(): int
    {
        PermissionSync::run();

        $this->info('Permissions synchronisées.');

        return self::SUCCESS;
    }
}
