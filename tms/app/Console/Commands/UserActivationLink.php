<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenantOption;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\ActivationLink;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Operator recovery: a new activation link for an account that never chose
 * its password (lost invitation e-mail, expired link), or an invitation for a
 * new address (--role). Never sets nor prints a password.
 */
class UserActivationLink extends Command
{
    use ResolvesTenantOption;

    protected $signature = 'tms:user:activation-link
        {email : Adresse e-mail du compte}
        {--tenant= : Identifiant (slug) de l\'organisme (défaut : TMS_DEFAULT_TENANT)}
        {--role= : Rôle, obligatoire pour une nouvelle adresse (admin_organisme, direction, commercial… ou libellé d\'un rôle personnalisé)}
        {--name= : Nom affiché, pour une nouvelle adresse (défaut : partie locale de l\'e-mail)}';

    protected $description = 'Émet un nouveau lien d\'activation pour un compte non activé ou une nouvelle adresse';

    public function handle(TenantContext $tenants, AuditRecorder $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            $this->error("Adresse e-mail invalide : « {$email} ».");

            return self::FAILURE;
        }

        $tenant = $this->tenantFromOption();
        if ($tenant === null) {
            return self::FAILURE;
        }

        return $tenants->run($tenant, fn () => $this->issue($tenant, $email, (string) $this->option('role'), $audit));
    }

    /**
     * A role of the organisme by its name (admin_organisme…) or its label
     * (case and accents ignored, for a custom role).
     */
    private function findRole(string $option): ?RoleModel
    {
        $wanted = RoleModel::normaliseLabel($option);

        return RoleModel::named($option)
            ?? RoleModel::query()->ordered()->get()->first(fn (RoleModel $role) => RoleModel::normaliseLabel($role->label()) === $wanted);
    }

    private function issue(Tenant $tenant, string $email, string $roleOption, AuditRecorder $audit): int
    {
        $role = null;
        if ($roleOption !== '') {
            $role = $this->findRole($roleOption);
            if ($role === null) {
                $this->error("Rôle inconnu : « {$roleOption} ». Rôles possibles : ".implode(', ', array_merge(
                    array_column(Role::cases(), 'value'),
                    RoleModel::query()->ordered()->where('system', false)->get()->map(fn (RoleModel $r) => '« '.$r->label().' »')->all(),
                )).'.');

                return self::FAILURE;
            }
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null && ! $user->isActive()) {
            $this->error('Ce compte est désactivé : aucun lien d\'activation n\'est émis.');

            return self::FAILURE;
        }

        if ($user !== null && $user->password !== null) {
            $this->error('Ce compte est déjà activé : utilisez « Mot de passe oublié » sur la page de connexion'
                .' (ou tms:user:reset-2fa si la double authentification est perdue).');

            return self::FAILURE;
        }

        $role ??= $user?->role();
        if ($role === null) {
            $this->error('Aucun compte avec cette adresse : précisez le rôle avec --role (par exemple --role=admin_organisme).');

            return self::FAILURE;
        }

        $name = $user->name ?? ((string) $this->option('name') ?: strstr($email, '@', true));

        $invitation = DB::transaction(function () use ($email, $name, $role, $audit): Invitation {
            $invitation = Invitation::issue($email, (string) $name, $role);
            $audit->record('users.activation_reissued', $invitation, [], [
                'email' => $email,
                'role' => $role->name,
                'role_label' => $role->label(),
                'by' => 'console',
            ]);

            return $invitation;
        });

        $this->info("Lien d'activation émis pour {$email} ({$role->label()}) dans l'organisme « {$tenant->name} ».");
        $this->line('Valable '.Invitation::VALIDITY_HOURS.' h, usage unique ; tout lien précédent est annulé.');
        $this->line('Transmettez-le uniquement au titulaire de cette adresse :');
        $this->line(ActivationLink::url((string) $invitation->plainToken, $tenant));

        return self::SUCCESS;
    }
}
