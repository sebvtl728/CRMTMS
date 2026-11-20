<?php

namespace App\Console\Commands;

use App\Modules\Core\Platform\Sandbox;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Console\Command;

/**
 * Resets the local sandbox (never on a server: refused unless
 * TMS_SANDBOX=true and APP_ENV=local). --mode=work erases the working data,
 * --mode=all starts from a new organisme keeping the given administrator.
 */
class SandboxReset extends Command
{
    protected $signature = 'tms:sandbox:reset {--mode=work : work (données de travail) ou all (repartir de zéro)} {--admin= : e-mail de l\'administrateur gardé (mode all)} {--force : sans confirmation}';

    protected $description = 'Réinitialise le bac à sable local (jamais sur un serveur)';

    public function handle(Sandbox $sandbox): int
    {
        if (! Sandbox::enabled()) {
            $this->error('Réinitialisation refusée : ce TMS n\'est pas un bac à sable (TMS_SANDBOX=true et APP_ENV=local requis).');

            return self::FAILURE;
        }
        $mode = $this->option('mode') === Sandbox::ALL ? Sandbox::ALL : Sandbox::WORK;
        $tenant = Tenant::query()->where('slug', config('tms.default_tenant'))->firstOrFail();
        app(TenantContext::class)->set($tenant);
        $admin = $this->option('admin') !== null
            ? User::query()->where('email', $this->option('admin'))->first()
            : User::query()->role('admin_organisme')->orderBy('created_at')->first();
        if ($admin === null) {
            $this->error('Administrateur introuvable.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Effacer le bac à sable ('.($mode === Sandbox::ALL ? 'repartir de zéro' : 'données de travail').') ?')) {
            return self::FAILURE;
        }
        $sandbox->reset($mode, $admin);
        $this->info('Bac à sable réinitialisé.');

        return self::SUCCESS;
    }
}
