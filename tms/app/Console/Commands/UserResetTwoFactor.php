<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenantOption;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\TwoFactorAuthenticator;
use App\Modules\Core\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Operator recovery of a lost second factor (phone and recovery codes lost):
 * clears the secret and the recovery codes and closes every session. A role
 * that requires 2FA (administrator, direction) is sent to the enrolment at
 * its next login (EnsureTwoFactorEnrolled).
 */
class UserResetTwoFactor extends Command
{
    use ResolvesTenantOption;

    protected $signature = 'tms:user:reset-2fa
        {email : Adresse e-mail du compte}
        {--tenant= : Identifiant (slug) de l\'organisme (défaut : TMS_DEFAULT_TENANT)}';

    protected $description = 'Réinitialise la double authentification d\'un compte (secret, codes de secours, sessions)';

    public function handle(TenantContext $tenants, TwoFactorAuthenticator $twoFactor, AuditRecorder $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $tenant = $this->tenantFromOption();
        if ($tenant === null) {
            return self::FAILURE;
        }

        return $tenants->run($tenant, fn () => $this->reset($tenant, $email, $twoFactor, $audit));
    }

    private function reset(Tenant $tenant, string $email, TwoFactorAuthenticator $twoFactor, AuditRecorder $audit): int
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Aucun compte « {$email} » dans l'organisme « {$tenant->name} ».");

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $twoFactor, $audit) {
            $twoFactor->disable($user);
            $user->revokeOtherSessions(null);
            $audit->record('auth.2fa_reset_by_operator', $user, [], ['by' => 'console']);
        });

        $this->info("Double authentification réinitialisée pour {$email} ; ses sessions ont été fermées.");
        $this->line($user->role()?->requires2fa()
            ? 'À la prochaine connexion, le compte devra enrôler un nouvel appareil.'
            : 'Le compte pourra réactiver la double authentification depuis ses paramètres.');

        return self::SUCCESS;
    }
}
