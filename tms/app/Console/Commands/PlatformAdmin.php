<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenantOption;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Console\Command;

/**
 * Names (or, with --revoke, removes) the platform operator: the account that
 * opens Horizon (queues of every organisme). From the server only (whoever
 * runs it already holds the installation); never from a screen. Journaled.
 */
class PlatformAdmin extends Command
{
    use ResolvesTenantOption;

    protected $signature = 'tms:platform-admin
        {email : Adresse e-mail du compte}
        {--revoke : Retire le droit au lieu de le donner}
        {--tenant= : Identifiant (slug) de l\'organisme (défaut : TMS_DEFAULT_TENANT)}';

    protected $description = 'Désigne (ou retire) l\'opérateur de la plateforme, qui peut ouvrir Horizon';

    public function handle(TenantContext $tenants): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $tenant = $this->tenantFromOption();
        if ($tenant === null) {
            return self::FAILURE;
        }

        return $tenants->run($tenant, fn () => $this->apply($tenant, $email));
    }

    private function apply(Tenant $tenant, string $email): int
    {
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("Aucun utilisateur « {$email} » dans l'organisme « {$tenant->name} ».");

            return self::FAILURE;
        }
        $grant = ! $this->option('revoke');
        if ($grant && (! $user->isActive() || ! $user->hasTwoFactorEnabled())) {
            $this->error("Le compte « {$email} » doit être actif et avoir activé la double authentification avant de devenir opérateur.");

            return self::FAILURE;
        }
        // The change itself is journaled by the user's audit (is_platform_admin).
        $user->forceFill(['is_platform_admin' => $grant])->save();
        $this->info($grant ? "{$email} peut maintenant ouvrir Horizon (/horizon)." : "{$email} ne peut plus ouvrir Horizon.");

        return self::SUCCESS;
    }
}
